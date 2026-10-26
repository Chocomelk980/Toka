<?php
session_start();
require_once __DIR__ . '/../LOGIN_PAGE/access.php';
tokaRequireRole();
require_once __DIR__ . '/../LOGIN_PAGE/dbconnect.php';
require_once __DIR__ . '/cycle_helpers.php';
require_once __DIR__ . '/group_limits.php';
require_once __DIR__ . '/freeze_poll_helpers.php';

if (!isset($_SESSION['user']) || (int) ($_SESSION['user']['user_id'] ?? 0) < 1) {
    header('Location: ../LOGIN_PAGE/index.php');
    exit;
}

$userId = (int) $_SESSION['user']['user_id'];
$username = $_SESSION['user']['username'] ?? 'User';
$groupId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    ?: filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$group = null;
$members = [];
$channels = [];
$rounds = [];
$activity = [];
$loadError = '';
$actionMessage = '';
$submittedActionNotice = '';
$currentMember = null;
$currentRound = null;
$memberPayments = [];
$trustScoreHistory = [];
$trustScoreHistoryUnavailable = false;
$pendingRequests = [];
$freezePolls = [];
$activeFreezePoll = null;
$unreadFreezeOutcome = null;
$receiptClaimed = false;
$currentMemberPaymentLocked = false;
$openPaymentDialog = false;
$paymentFormState = ['channel_id' => '', 'transaction_no' => ''];
if (empty($_SESSION['freeze_poll_csrf'])) $_SESSION['freeze_poll_csrf'] = bin2hex(random_bytes(32));
$freezePollToken = $_SESSION['freeze_poll_csrf'];
$freezeReasonOptions = [
    'Unverified Share',
    'Pot Disbursement Shortage/Delay',
    'Invalid/Compromised Payment Amount',
    'Unresponsive Leadership',
    'Mass Member Default',
    'Other/Manual Dispute',
];
$freezeOtherReason = 'Other/Manual Dispute';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function groupTrustScoreHistory(PDO $pdo, int $groupId): array
{
    $stmt = $pdo->prepare(
        'SELECT gm.member_id, rs.round_number, al.activity_type, al.details
         FROM audit_logs al
         JOIN payments p ON p.payment_id = al.payment_id
         JOIN group_members gm ON gm.member_id = p.member_id AND gm.group_id = al.group_id
         JOIN round_schedules rs ON rs.round_id = p.round_id AND rs.group_id = al.group_id
         WHERE al.group_id = :group_id
           AND al.activity_type IN (\'Payment Verified\', \'Payment Marked Missed\')
         ORDER BY al.timestamp DESC, al.log_id DESC'
    );
    $stmt->execute([':group_id' => $groupId]);
    $history = [];
    while ($entry = $stmt->fetch()) {
        $memberId = (int) $entry['member_id'];
        if (count($history[$memberId] ?? []) >= 5
            || !preg_match('/;\s*trust score ([+-]\d+) points\.?$/iD', (string) $entry['details'], $match)) {
            continue;
        }
        $adjustment = (int) $match[1];
        $reason = $entry['activity_type'] === 'Payment Marked Missed' ? 'Missed payment' : match ($adjustment) {
            5 => 'Paid on time',
            -10 => '1-2 days late',
            -25 => '3+ days late',
            default => 'Payment adjustment',
        };
        $history[$memberId][] = [
            'round_number' => (int) $entry['round_number'],
            'adjustment' => $adjustment,
            'reason' => $reason,
        ];
    }
    return $history;
}

function groupDate(?string $value, string $format = 'M j, Y'): string
{
    if (!$value) return 'Not scheduled';
    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Throwable $e) {
        return 'Not scheduled';
    }
}

function requestAge(string $timestamp): string
{
    $minutes = max(0, (int) floor((time() - strtotime($timestamp)) / 60));
    if ($minutes < 1) return 'Just now';
    if ($minutes < 60) return $minutes . ' min' . ($minutes === 1 ? '' : 's') . ' ago';
    $hours = (int) floor($minutes / 60);
    if ($hours < 24) return $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ago';
    $days = (int) floor($hours / 24);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

function actionButton(string $label, bool $enabled, string $action, string $token): string
{
    $disabled = $enabled ? '' : ' disabled';
    $reasonInput = $action === 'freeze' ? '<input type="hidden" name="reason" value="">' : '';
    $buttonClass = $action === 'freeze'
        ? 'operator-button pay-button'
        : ($action === 'claim' ? 'action-button action-button-primary' : 'action-button');
    return '<form method="post" class="action-form"><input type="hidden" name="csrf_token" value="' .
        h($token) . '"><input type="hidden" name="action" value="' . h($action) .
        '">' . $reasonInput . '<button class="' . $buttonClass . '" type="submit"' . $disabled . '>' . h($label) . '</button></form>';
}

if ($groupId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT pg.*, gm.role AS viewer_role,
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
                'SELECT gm.member_id, gm.user_id, gm.role, gm.assigned_slot_number,
                        gm.joined_at, u.username, u.trust_score, u.account_status
                 FROM group_members gm JOIN users u ON u.user_id = gm.user_id
                 WHERE gm.group_id = :group_id
                 ORDER BY gm.assigned_slot_number'
            );
            $stmt->execute([':group_id' => $groupId]);
            $members = $stmt->fetchAll();

            foreach ($members as $member) {
                if ((int) $member['user_id'] === $userId) $currentMember = $member;
            }

            $freezePolls = tokaFreezePollsForUser($pdo, $userId, (int) $groupId);
            foreach ($freezePolls as $freezePoll) {
                if ($freezePoll['poll_status'] === 'Active' && $activeFreezePoll === null) {
                    $activeFreezePoll = $freezePoll;
                }
                if ($freezePoll['poll_status'] !== 'Active'
                    && $freezePoll['outcome_seen_at'] === null && $unreadFreezeOutcome === null) {
                    $unreadFreezeOutcome = $freezePoll;
                }
            }

            $stmt = $pdo->prepare(
                'SELECT channel_id, channel_type, account_name, account_number
                 FROM group_payment_channels WHERE group_id = :group_id ORDER BY channel_id'
            );
            $stmt->execute([':group_id' => $groupId]);
            $channels = $stmt->fetchAll();

            // Older active groups may have been activated before round schedules were created.
            // Build their cycle once so the current round actions can work normally.
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM round_schedules WHERE group_id = :group_id');
            $stmt->execute([':group_id' => $groupId]);
            $existingRoundCount = (int) $stmt->fetchColumn();
            if ($group['group_status'] === 'Active' && $existingRoundCount === 0
                && count($members) === (int) $group['total_member_slots']) {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'SELECT group_status, total_member_slots, first_payment_due, payment_frequency
                     FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
                );
                $stmt->execute([':group_id' => $groupId]);
                $lockedGroup = $stmt->fetch();
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM round_schedules WHERE group_id = :group_id');
                $stmt->execute([':group_id' => $groupId]);
                $existingRoundCount = (int) $stmt->fetchColumn();
                if ($lockedGroup && $lockedGroup['group_status'] === 'Active' && $existingRoundCount === 0) {
                    $stmt = $pdo->prepare(
                        'SELECT gm.member_id, gm.role, u.trust_score
                         FROM group_members gm JOIN users u ON u.user_id = gm.user_id
                         WHERE gm.group_id = :group_id
                         ORDER BY gm.assigned_slot_number FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $groupId]);
                    $cycleMembers = $stmt->fetchAll();
                    if (count($cycleMembers) === (int) $lockedGroup['total_member_slots']) {
                        $firstDue = new DateTimeImmutable($lockedGroup['first_payment_due']);
                        tokaCreateCycleRounds($pdo, $groupId, $cycleMembers, $firstDue, $lockedGroup['payment_frequency']);
                    }
                }
                $pdo->commit();
            }

            $stmt = $pdo->prepare(
                'SELECT rs.round_id, rs.round_number, rs.receiver_member_id, rs.target_deadline,
                        rs.round_status, rs.actual_disbursement_date, rs.recipient_claimed_at,
                        rs.operator_approved_at, rs.remarks,
                        receiver.username AS receiver_name, receiver_member.role AS receiver_role,
                        COALESCE(SUM(CASE WHEN p.payment_status = "Verified" THEN p.amount_due ELSE 0 END), 0) AS collected,
                        COUNT(DISTINCT CASE WHEN p.payment_status = "Verified" THEN p.member_id END) AS verified_member_count,
                        COUNT(DISTINCT CASE WHEN p.payment_status = "Pending Verification" THEN p.member_id END) AS pending_member_count
                 FROM round_schedules rs
                 JOIN group_members receiver_member ON receiver_member.member_id = rs.receiver_member_id
                 JOIN users receiver ON receiver.user_id = receiver_member.user_id
                 LEFT JOIN payments p ON p.round_id = rs.round_id
                 WHERE rs.group_id = :group_id
                 GROUP BY rs.round_id, rs.round_number, rs.receiver_member_id, rs.target_deadline,
                          rs.round_status, rs.actual_disbursement_date, rs.recipient_claimed_at,
                          rs.operator_approved_at, rs.remarks, receiver.username, receiver_member.role
                 ORDER BY rs.round_number'
            );
            $stmt->execute([':group_id' => $groupId]);
            $rounds = $stmt->fetchAll();

            foreach ($rounds as $round) {
                if ($round['round_status'] !== 'Paid Out') {
                    $currentRound = $round;
                    break;
                }
            }
            if (!$currentRound && $rounds) $currentRound = $rounds[count($rounds) - 1];

            if ($currentRound) {
                if ($group['group_status'] === 'Active'
                    && $currentRound['round_status'] === 'Ongoing'
                    && $currentRound['recipient_claimed_at'] === null
                    && tokaCycleGraceExpired($currentRound['target_deadline'])) {
                    tokaApplyMissedCyclePayments(
                        $pdo, (int) $groupId, (int) $currentRound['round_id'], $userId
                    );
                    $stmt = $pdo->prepare(
                        'SELECT gm.user_id, u.trust_score
                         FROM group_members gm JOIN users u ON u.user_id = gm.user_id
                         WHERE gm.group_id = :group_id'
                    );
                    $stmt->execute([':group_id' => $groupId]);
                    $trustScores = [];
                    foreach ($stmt->fetchAll() as $scoreRow) {
                        $trustScores[(int) $scoreRow['user_id']] = (int) $scoreRow['trust_score'];
                    }
                    foreach ($members as &$member) {
                        if (isset($trustScores[(int) $member['user_id']])) {
                            $member['trust_score'] = $trustScores[(int) $member['user_id']];
                        }
                        if ((int) $member['user_id'] === $userId) $currentMember = $member;
                    }
                    unset($member);
                }
                $stmt = $pdo->prepare(
                    'SELECT p.payment_id, p.member_id, p.payment_status, p.is_member_confirmed,
                            p.is_cooperator_verified, p.is_operator_verified, p.transaction_no,
                            p.submitted_at, p.amount_due, p.late_fee_applied, gpc.channel_type
                     FROM payments p
                     LEFT JOIN group_payment_channels gpc ON gpc.channel_id = p.channel_id
                     WHERE p.round_id = :round_id
                     ORDER BY p.submitted_at DESC, p.payment_id DESC'
                );
                $stmt->execute([':round_id' => $currentRound['round_id']]);
                foreach ($stmt->fetchAll() as $payment) {
                    if ((int) $payment['member_id'] === (int) ($currentMember['member_id'] ?? 0)
                        && in_array($payment['payment_status'], ['Pending Verification', 'Verified'], true)) {
                        $currentMemberPaymentLocked = true;
                    }
                    if (!isset($memberPayments[(int) $payment['member_id']])) {
                        $memberPayments[(int) $payment['member_id']] = $payment;
                    }
                }

                $receiptClaimed = $currentRound['recipient_claimed_at'] !== null;
            }

            $stmt = $pdo->prepare(
                'SELECT al.activity_type, al.details, al.timestamp, u.username
                 FROM audit_logs al JOIN users u ON u.user_id = al.actor_user_id
                 WHERE al.group_id = :group_id ORDER BY al.timestamp DESC LIMIT 12'
            );
            $stmt->execute([':group_id' => $groupId]);
            $activity = $stmt->fetchAll();

            try {
                $trustScoreHistory = groupTrustScoreHistory($pdo, (int) $groupId);
            } catch (PDOException $e) {
                $trustScoreHistoryUnavailable = true;
                error_log('Trust score history could not be loaded: ' . $e->getMessage());
            }

            if ($group['viewer_role'] === 'Main Operator') {
                $stmt = $pdo->prepare(
                    'SELECT jr.request_id, jr.user_id, jr.requested_at, u.username
                     FROM join_requests jr
                     JOIN users u ON u.user_id = jr.user_id
                     WHERE jr.group_id = :group_id AND jr.request_status = :status
                     ORDER BY jr.requested_at ASC, jr.request_id ASC'
                );
                $stmt->execute([':group_id' => $groupId, ':status' => 'Pending']);
                $pendingRequests = $stmt->fetchAll();
                $occupied = [];
                foreach ($members as $member) $occupied[(int) $member['assigned_slot_number']] = true;
                $nextSlot = 1;
                foreach ($pendingRequests as &$request) {
                    while ($nextSlot <= (int) $group['total_member_slots'] && isset($occupied[$nextSlot])) $nextSlot++;
                    $request['suggested_slot'] = $nextSlot <= (int) $group['total_member_slots'] ? $nextSlot : 0;
                    if ($request['suggested_slot'] > 0) $occupied[$nextSlot] = true;
                    $nextSlot++;
                    $request['age'] = requestAge($request['requested_at']);
                }
                unset($request);
            }
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Group dashboard DB error: ' . $e->getMessage());
        $loadError = 'We could not load this group right now. Please refresh the page to try again.';
    }
}

if (!$group && $loadError === '') http_response_code(404);

$claimApproverRole = ($currentRound['receiver_role'] ?? '') === 'Main Operator'
    ? 'Co-Operator'
    : 'Main Operator';

if ($group && isset($_SESSION['group_dashboard_flash'][$groupId])) {
    $actionMessage = (string) $_SESSION['group_dashboard_flash'][$groupId];
    if ($actionMessage === 'Payment submitted.') {
        $submittedActionNotice = 'Payment submitted';
    } elseif (str_starts_with($actionMessage, 'Pot claim submitted. It is waiting for the ')
        && str_ends_with($actionMessage, ' to approve it.')) {
        $submittedActionNotice = 'Pot claim submitted';
    } elseif (in_array($actionMessage, [
        'Payment verified.',
        'Payment approved. It is awaiting Main Operator approval.',
    ], true)) {
        $submittedActionNotice = $actionMessage;
    } elseif (in_array($actionMessage, [
        'Pot claim approved. The next round has started.',
        'The final pot claim was approved. This group cycle is complete.',
    ], true)) {
        $submittedActionNotice = $actionMessage;
    }
    unset($_SESSION['group_dashboard_flash'][$groupId]);
}

$memberCount = (int) ($group['member_count'] ?? 0);
$groupStatus = (string) ($group['group_status'] ?? 'Waiting');
$roundStatus = $currentRound['round_status'] ?? '';
$isOperator = ($currentMember['role'] ?? '') === 'Main Operator';
$isCoOperator = ($currentMember['role'] ?? '') === 'Co-Operator';
$isClaimApprover = ($claimApproverRole === 'Main Operator' && $isOperator)
    || ($claimApproverRole === 'Co-Operator' && $isCoOperator);
$canFreezeGroup = in_array($currentMember['role'] ?? '', ['Main Operator', 'Co-Operator'], true);
$isRecipient = $currentRound && (int) $currentRound['receiver_member_id'] === (int) ($currentMember['member_id'] ?? 0);
$hasDisbursed = $currentRound && $currentRound['actual_disbursement_date'] !== null;
$receiptClaimed = $currentRound && $currentRound['recipient_claimed_at'] !== null;
$potOperatorApproved = $currentRound && $currentRound['operator_approved_at'] !== null;
$allMembersVerified = $currentRound && $memberCount > 0
    && (int) $currentRound['verified_member_count'] >= $memberCount;
$pendingMemberCount = (int) ($currentRound['pending_member_count'] ?? 0);
$paymentGraceDeadline = $currentRound && !empty($currentRound['target_deadline'])
    ? tokaCycleGraceDeadline(new DateTimeImmutable($currentRound['target_deadline']))
    : null;
$paymentGraceExpired = $paymentGraceDeadline !== null && new DateTimeImmutable('now') >= $paymentGraceDeadline;
$claimPaymentConditionMet = $allMembersVerified || ($paymentGraceExpired && $pendingMemberCount === 0);
$canClaimPot = $groupStatus === 'Active' && $currentRound && $roundStatus === 'Ongoing'
    && $isRecipient && $claimPaymentConditionMet && !$receiptClaimed && !$potOperatorApproved;
$canApprovePotClaim = $groupStatus === 'Active' && $currentRound && $roundStatus === 'Ongoing'
    && $isClaimApprover && $receiptClaimed && !$potOperatorApproved;
$freezePendingVotes = $activeFreezePoll
    ? max(0, (int) $activeFreezePoll['eligible_count'] - (int) $activeFreezePoll['approvals'] - (int) $activeFreezePoll['rejections'])
    : 0;
$freezeVotePopup = $activeFreezePoll && $activeFreezePoll['my_vote'] === null ? $activeFreezePoll : null;
$freezeOutcomePopup = $freezeVotePopup ? null : $unreadFreezeOutcome;
$freezeOutcomeApproved = $freezeOutcomePopup && $freezeOutcomePopup['poll_status'] === 'Passed';
$freezeOutcomeRejected = $freezeOutcomePopup
    && (int) $freezeOutcomePopup['rejections'] * 2 > (int) $freezeOutcomePopup['eligible_count'];
$freezeOutcomeTitle = $freezeOutcomeApproved
    ? 'Freeze Request Approved'
    : ($freezeOutcomeRejected ? 'Freeze Request Rejected' : 'No Majority Reached');
$freezeOutcomeMessage = $freezeOutcomeApproved
    ? 'Members approved the freeze request and the group was frozen.'
    : ($freezeOutcomeRejected
        ? 'Members rejected the freeze request. The group remains active.'
        : 'The vote ended without a majority. The group remains active.');

$csrfToken = '';
if ($group) {
    $tokenKey = 'group_dashboard_token_' . $groupId;
    if (!isset($_SESSION[$tokenKey])) $_SESSION[$tokenKey] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION[$tokenKey];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $group && $loadError === '') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $tokenKey = 'group_dashboard_token_' . $groupId;
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $validToken = is_string($submittedToken) && isset($_SESSION[$tokenKey])
        && hash_equals($_SESSION[$tokenKey], $submittedToken);
    $isOperator = ($currentMember['role'] ?? '') === 'Main Operator';
    $isCoOperator = ($currentMember['role'] ?? '') === 'Co-Operator';
    $canFreezeGroup = in_array($currentMember['role'] ?? '', ['Main Operator', 'Co-Operator'], true);
    $isRecipient = $currentRound && (int) $currentRound['receiver_member_id'] === (int) ($currentMember['member_id'] ?? 0);

    if (!$validToken) {
        $actionMessage = 'This action expired. Refresh the page and try again.';
    } elseif (in_array($action, ['approve_join', 'reject_join'], true) && $isOperator) {
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$requestId) {
            $actionMessage = 'That join request could not be found.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT total_member_slots, group_status FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE');
                $stmt->execute([':group_id' => $groupId]);
                $lockedGroup = $stmt->fetch();
                $stmt = $pdo->prepare(
                    'SELECT jr.request_id, jr.user_id, u.is_admin FROM join_requests jr
                     JOIN users u ON u.user_id = jr.user_id
                     WHERE jr.request_id = :request_id AND jr.group_id = :group_id
                       AND jr.request_status = :status FOR UPDATE'
                );
                $stmt->execute([':request_id' => $requestId, ':group_id' => $groupId, ':status' => 'Pending']);
                $request = $stmt->fetch();

                if (!$lockedGroup || !$request) {
                    $pdo->rollBack();
                    $actionMessage = 'This join request has already been handled.';
                } elseif ($action === 'approve_join' && (bool) $request['is_admin']) {
                    $pdo->rollBack();
                    $actionMessage = 'The admin account cannot join groups.';
                } elseif ($action === 'approve_join' && !in_array($lockedGroup['group_status'], ['Waiting', 'Active'], true)) {
                    $pdo->rollBack();
                    $actionMessage = 'This group is not accepting new members right now.';
                } elseif ($action === 'reject_join') {
                    $stmt = $pdo->prepare(
                        'UPDATE join_requests SET request_status = :status, responded_at = NOW(), responded_by = :operator
                         WHERE request_id = :request_id AND request_status = :pending'
                    );
                    $stmt->execute([
                        ':status' => 'Rejected', ':operator' => $userId,
                        ':request_id' => $requestId, ':pending' => 'Pending',
                    ]);
                    $pdo->prepare(
                        'INSERT INTO audit_logs (group_id, actor_user_id, activity_type, details, verification_status)
                         VALUES (:group_id, :actor, :activity, :details, :verification)'
                    )->execute([
                        ':group_id' => $groupId, ':actor' => $userId,
                        ':activity' => 'Join Request Declined',
                        ':details' => 'A join request was declined by the Main Operator',
                        ':verification' => 'Main Operator',
                    ]);
                    $pdo->commit();
                    header('Location: group_dashboard.php?id=' . $groupId);
                    exit;
                } else {
                    tokaLockGroupLimitUser($pdo, (int) $request['user_id']);
                    $limitError = tokaGroupLimitError($pdo, (int) $request['user_id'], true);
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = :group_id');
                    $stmt->execute([':group_id' => $groupId]);
                    if ((int) $stmt->fetchColumn() >= (int) $lockedGroup['total_member_slots']) {
                        $pdo->rollBack();
                        $actionMessage = 'The group is full. This request is still waiting for your decision.';
                    } else {
                        $stmt = $pdo->prepare(
                            'SELECT member_id FROM group_members WHERE group_id = :group_id AND user_id = :user_id LIMIT 1'
                        );
                        $stmt->execute([':group_id' => $groupId, ':user_id' => $request['user_id']]);
                        $alreadyMember = (bool) $stmt->fetch();

                        if (!$alreadyMember && $limitError !== null) {
                            $pdo->rollBack();
                            $actionMessage = $limitError;
                        } elseif (!$alreadyMember) {
                            $stmt = $pdo->prepare('SELECT assigned_slot_number FROM group_members WHERE group_id = :group_id');
                            $stmt->execute([':group_id' => $groupId]);
                            $occupiedSlots = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                            $assignedSlot = 1;
                            while (in_array($assignedSlot, $occupiedSlots, true)
                                && $assignedSlot <= (int) $lockedGroup['total_member_slots']) {
                                $assignedSlot++;
                            }
                            if ($assignedSlot > (int) $lockedGroup['total_member_slots']) {
                                $pdo->rollBack();
                                $actionMessage = 'The group has no open slot. This request is still waiting.';
                            } else {
                                $pdo->prepare(
                                    'INSERT INTO group_members (group_id, user_id, role, assigned_slot_number)
                                     VALUES (:group_id, :user_id, :role, :slot)'
                                )->execute([
                                    ':group_id' => $groupId, ':user_id' => $request['user_id'],
                                    ':role' => 'Member', ':slot' => $assignedSlot,
                                ]);
                            }
                        }

                        if ($actionMessage === '') {
                            $pdo->prepare(
                                'UPDATE join_requests
                                 SET request_status = :status, responded_at = NOW(), responded_by = :operator
                                 WHERE request_id = :request_id AND request_status = :pending'
                            )->execute([
                                ':status' => 'Approved', ':operator' => $userId,
                                ':request_id' => $requestId, ':pending' => 'Pending',
                            ]);
                            $pdo->prepare(
                                'INSERT INTO audit_logs (group_id, actor_user_id, activity_type, details, verification_status)
                                 VALUES (:group_id, :actor, :activity, :details, :verification)'
                            )->execute([
                                ':group_id' => $groupId, ':actor' => $userId,
                                ':activity' => 'Join Request Approved',
                                ':details' => $alreadyMember
                                    ? 'A duplicate join request was closed because the user is already a member'
                                    : 'A new member was approved for slot ' . $assignedSlot,
                                ':verification' => 'Main Operator',
                            ]);
                            $pdo->commit();
                            header('Location: group_dashboard.php?id=' . $groupId);
                            exit;
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Join request decision error: ' . $e->getMessage());
                $actionMessage = 'We could not update this join request. Please try again.';
            }
        }
    } elseif ($action === 'submit_payment') {
        $paymentFormState['channel_id'] = is_scalar($_POST['channel_id'] ?? null) ? (string) $_POST['channel_id'] : '';
        $paymentFormState['transaction_no'] = is_string($_POST['transaction_no'] ?? null)
            ? trim($_POST['transaction_no']) : '';
        $channelId = filter_var($paymentFormState['channel_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($group['group_status'] !== 'Active' || !$currentRound
            || $currentRound['round_status'] !== 'Ongoing' || $hasDisbursed || $receiptClaimed) {
            $actionMessage = 'Payments can only be submitted during an active, undistributed round.';
        } elseif (!$channelId || $paymentFormState['transaction_no'] === ''
            || strlen($paymentFormState['transaction_no']) > 100) {
            $actionMessage = 'Choose a payment channel and enter a transaction number (up to 100 characters).';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'SELECT group_status, contribution_amount, late_fee_rate
                     FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
                );
                $stmt->execute([':group_id' => $groupId]);
                $lockedGroup = $stmt->fetch();

                $stmt = $pdo->prepare(
                    'SELECT round_id, target_deadline, round_status, actual_disbursement_date, recipient_claimed_at
                     FROM round_schedules WHERE round_id = :round_id AND group_id = :group_id FOR UPDATE'
                );
                $stmt->execute([':round_id' => $currentRound['round_id'], ':group_id' => $groupId]);
                $lockedRound = $stmt->fetch();

                $stmt = $pdo->prepare(
                    'SELECT member_id FROM group_members
                     WHERE group_id = :group_id AND user_id = :user_id LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
                $lockedMemberId = $stmt->fetchColumn();

                $stmt = $pdo->prepare(
                    'SELECT channel_id FROM group_payment_channels
                     WHERE channel_id = :channel_id AND group_id = :group_id LIMIT 1'
                );
                $stmt->execute([':channel_id' => $channelId, ':group_id' => $groupId]);
                $validChannel = (bool) $stmt->fetchColumn();

                $stmt = $pdo->prepare(
                    'SELECT payment_id FROM payments
                     WHERE round_id = :round_id AND member_id = :member_id
                       AND payment_status IN (:pending, :verified)
                     LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([
                    ':round_id' => $currentRound['round_id'], ':member_id' => (int) $lockedMemberId,
                    ':pending' => 'Pending Verification', ':verified' => 'Verified',
                ]);
                $existingPayment = $stmt->fetchColumn();

                if (!$lockedGroup || !$lockedMemberId || !$validChannel) {
                    throw new RuntimeException('The selected member or payment channel is no longer available.');
                }
                if ($lockedGroup['group_status'] !== 'Active' || !$lockedRound
                    || $lockedRound['round_status'] !== 'Ongoing'
                    || $lockedRound['actual_disbursement_date'] !== null
                    || $lockedRound['recipient_claimed_at'] !== null) {
                    throw new RuntimeException('The current round is no longer accepting payments.');
                }
                if (tokaCycleGraceExpired($lockedRound['target_deadline'])) {
                    throw new RuntimeException('The three-day payment grace period has ended.');
                }
                if ($existingPayment) {
                    throw new RuntimeException('Your payment for this round is already awaiting verification or has been verified.');
                }

                $cutoff = tokaCycleGraceDeadline(new DateTimeImmutable($lockedRound['target_deadline']));
                $secondsLate = (new DateTimeImmutable('now'))->getTimestamp() - $cutoff->getTimestamp();
                $lateDays = $secondsLate > 0 ? (int) ceil($secondsLate / 86400) : 0;
                $lateFee = round((float) $lockedGroup['late_fee_rate'] * $lateDays, 2);
                $contribution = (float) $lockedGroup['contribution_amount'];
                $submitterRole = $currentMember['role'] ?? 'Member';
                $cooperatorVerifiedAtSubmission = $submitterRole === 'Co-Operator' ? 1 : 0;
                $operatorVerifiedAtSubmission = $submitterRole === 'Main Operator' ? 1 : 0;
                $nextApprover = $submitterRole === 'Co-Operator' ? 'Main Operator' : 'Co-Operator';

                $stmt = $pdo->prepare(
                    'INSERT INTO payments
                        (round_id, member_id, channel_id, amount_due, late_fee_applied, transaction_no,
                         is_member_confirmed, is_cooperator_verified, is_operator_verified, payment_status)
                     VALUES (:round_id, :member_id, :channel_id, :amount_due, :late_fee, :transaction_no,
                             :member_confirmed, :cooperator_verified, :operator_verified, :payment_status)'
                );
                $stmt->execute([
                    ':round_id' => $lockedRound['round_id'], ':member_id' => (int) $lockedMemberId,
                    ':channel_id' => $channelId, ':amount_due' => number_format($contribution, 2, '.', ''),
                    ':late_fee' => number_format($lateFee, 2, '.', ''),
                    ':transaction_no' => $paymentFormState['transaction_no'],
                    ':member_confirmed' => 1,
                    ':cooperator_verified' => $cooperatorVerifiedAtSubmission,
                    ':operator_verified' => $operatorVerifiedAtSubmission,
                    ':payment_status' => 'Pending Verification',
                ]);
                $paymentId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO audit_logs
                        (group_id, round_id, payment_id, actor_user_id, activity_type, details, verification_status)
                     VALUES (:group_id, :round_id, :payment_id, :actor, :activity, :details, :verification)'
                )->execute([
                    ':group_id' => $groupId, ':round_id' => $lockedRound['round_id'],
                    ':payment_id' => $paymentId, ':actor' => $userId,
                    ':activity' => 'Payment Submitted',
                    ':details' => 'Transaction ' . $paymentFormState['transaction_no'] . ' submitted for ' . $nextApprover . ' approval',
                    ':verification' => 'Pending ' . $nextApprover,
                ]);
                $pdo->commit();
                $_SESSION['group_dashboard_flash'][$groupId] = 'Payment submitted.';
                header('Location: group_dashboard.php?id=' . $groupId);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e instanceof RuntimeException) {
                    $actionMessage = $e->getMessage();
                } else {
                    error_log('Payment submission error: ' . $e->getMessage());
                    $actionMessage = 'Your payment could not be submitted. Please try again.';
                }
            }
        }
        $openPaymentDialog = true;
    } elseif ($action === 'review_payment') {
        $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
        if (!$paymentId || !in_array($decision, ['approve', 'reject'], true) || (!$isCoOperator && !$isOperator) || !$currentRound) {
            $actionMessage = 'This payment is not available for your approval.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'SELECT p.payment_id, p.member_id, p.payment_status, p.is_cooperator_verified,
                            p.is_operator_verified, p.transaction_no, p.submitted_at,
                            payer.user_id, rs.target_deadline, rs.round_status, rs.actual_disbursement_date,
                            rs.recipient_claimed_at, pg.group_status
                     FROM payments p
                     JOIN group_members payer_member ON payer_member.member_id = p.member_id
                     JOIN users payer ON payer.user_id = payer_member.user_id
                     JOIN round_schedules rs ON rs.round_id = p.round_id
                     JOIN paluwagan_groups pg ON pg.group_id = rs.group_id
                     WHERE p.payment_id = :payment_id AND p.round_id = :round_id
                       AND pg.group_id = :group_id
                     FOR UPDATE'
                );
                $stmt->execute([
                    ':payment_id' => $paymentId, ':round_id' => $currentRound['round_id'],
                    ':group_id' => $groupId,
                ]);
                $reviewPayment = $stmt->fetch();

                if (!$reviewPayment || $reviewPayment['group_status'] !== 'Active'
                    || $reviewPayment['round_status'] !== 'Ongoing'
                    || $reviewPayment['actual_disbursement_date'] !== null
                    || $reviewPayment['recipient_claimed_at'] !== null
                    || $reviewPayment['payment_status'] !== 'Pending Verification') {
                    throw new RuntimeException('This payment is no longer awaiting approval.');
                }

                $coOperatorApproved = (bool) $reviewPayment['is_cooperator_verified'];
                $operatorApproved = (bool) $reviewPayment['is_operator_verified'];
                $isCoOperatorStage = $isCoOperator && !$coOperatorApproved;
                $isMainOperatorStage = $isOperator && $coOperatorApproved && !$operatorApproved;
                if (!$isCoOperatorStage && !$isMainOperatorStage) {
                    throw new RuntimeException('This payment is not awaiting your approval stage.');
                }

                $approverRole = $isCoOperatorStage ? 'Co-Operator' : 'Main Operator';
                if ($decision === 'reject') {
                    $stmt = $pdo->prepare(
                        'UPDATE payments
                         SET payment_status = :unpaid, is_cooperator_verified = 0, is_operator_verified = 0
                         WHERE payment_id = :payment_id AND payment_status = :pending
                           AND is_cooperator_verified = :cooperator_verified
                           AND is_operator_verified = :operator_verified'
                    );
                    $stmt->execute([
                        ':unpaid' => 'Unpaid', ':payment_id' => $paymentId,
                        ':pending' => 'Pending Verification', ':cooperator_verified' => $coOperatorApproved ? 1 : 0,
                        ':operator_verified' => $operatorApproved ? 1 : 0,
                    ]);
                    $activity = 'Payment Rejected';
                    $verificationStatus = 'Rejected by ' . $approverRole;
                    $details = 'Transaction ' . ($reviewPayment['transaction_no'] ?: 'N/A') . ' rejected by ' . $approverRole;
                    $paymentIsVerified = false;
                } elseif ($isCoOperatorStage) {
                    $stmt = $pdo->prepare(
                        'UPDATE payments
                         SET is_cooperator_verified = 1,
                             payment_status = CASE WHEN is_operator_verified = 1 THEN :verified ELSE payment_status END
                         WHERE payment_id = :payment_id AND payment_status = :pending
                           AND is_cooperator_verified = 0 AND is_operator_verified = :operator_verified'
                    );
                    $stmt->execute([
                        ':verified' => 'Verified', ':payment_id' => $paymentId,
                        ':pending' => 'Pending Verification', ':operator_verified' => $operatorApproved ? 1 : 0,
                    ]);
                    $paymentIsVerified = $operatorApproved;
                    $activity = $paymentIsVerified ? 'Payment Verified' : 'Payment Approved by Co-Operator';
                    $verificationStatus = $paymentIsVerified ? 'Verified' : 'Pending Main Operator';
                    $details = 'Transaction ' . ($reviewPayment['transaction_no'] ?: 'N/A') . ' approved by Co-Operator'
                        . ($paymentIsVerified ? '; Main Operator approval skipped because the payer is the Main Operator' : '');
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE payments SET payment_status = :verified, is_operator_verified = 1
                         WHERE payment_id = :payment_id AND payment_status = :pending
                           AND is_cooperator_verified = 1 AND is_operator_verified = 0'
                    );
                    $stmt->execute([
                        ':verified' => 'Verified', ':payment_id' => $paymentId,
                        ':pending' => 'Pending Verification',
                    ]);
                    $activity = 'Payment Verified';
                    $verificationStatus = 'Verified';
                    $details = 'Transaction ' . ($reviewPayment['transaction_no'] ?: 'N/A') . ' verified by Main Operator';
                    $paymentIsVerified = true;
                }

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('This payment was updated by another approver. Refresh and try again.');
                }
                if ($paymentIsVerified) {
                    $trustAdjustment = tokaPaymentTrustAdjustment(
                        new DateTimeImmutable($reviewPayment['target_deadline']),
                        new DateTimeImmutable($reviewPayment['submitted_at'])
                    );
                    tokaAdjustUserTrustScore($pdo, (int) $reviewPayment['user_id'], $trustAdjustment);
                    $details .= '; trust score ' . ($trustAdjustment > 0 ? '+' : '') . $trustAdjustment . ' points';
                }
                $pdo->prepare(
                    'INSERT INTO audit_logs
                        (group_id, round_id, payment_id, actor_user_id, activity_type, details, verification_status)
                     VALUES (:group_id, :round_id, :payment_id, :actor, :activity, :details, :verification)'
                )->execute([
                    ':group_id' => $groupId, ':round_id' => $currentRound['round_id'],
                    ':payment_id' => $paymentId, ':actor' => $userId,
                    ':activity' => $activity, ':details' => $details, ':verification' => $verificationStatus,
                ]);
                $pdo->commit();
                $_SESSION['group_dashboard_flash'][$groupId] = $decision === 'reject'
                    ? 'Payment rejected.'
                    : ($paymentIsVerified ? 'Payment verified.' : 'Payment approved. It is awaiting Main Operator approval.');
                header('Location: group_dashboard.php?id=' . $groupId);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e instanceof RuntimeException) {
                    $actionMessage = $e->getMessage();
                    if ($actionMessage === 'This payment is not awaiting your approval stage.') {
                        $submittedActionNotice = $actionMessage;
                    }
                } else {
                    error_log('Payment approval error: ' . $e->getMessage());
                    $actionMessage = 'The payment approval could not be saved. Please try again.';
                }
            }
        }
    } elseif ($action === 'freeze' && $canFreezeGroup) {
        $freezeReason = trim(is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '');
        $otherReasonPrefix = $freezeOtherReason . ': ';
        if (str_starts_with($freezeReason, $otherReasonPrefix)) {
            $otherReasonDetails = trim(substr($freezeReason, strlen($otherReasonPrefix)));
            $otherReasonDetails = preg_replace('/\s+/u', ' ', $otherReasonDetails) ?? $otherReasonDetails;
            $freezeReason = $otherReasonPrefix . $otherReasonDetails;
            $validFreezeReason = $otherReasonDetails !== '';
        } else {
            $validFreezeReason = in_array($freezeReason, $freezeReasonOptions, true)
                && $freezeReason !== $freezeOtherReason;
        }
        $freezeReasonLength = function_exists('mb_strlen')
            ? mb_strlen($freezeReason, 'UTF-8')
            : strlen($freezeReason);
        if (!$validFreezeReason || $freezeReasonLength > 150) {
            $actionMessage = 'Choose a reason for freezing the group. Add details for Other/Manual Dispute.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT group_status FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE');
                $stmt->execute([':group_id' => $groupId]);
                $lockedStatus = $stmt->fetchColumn();
                if ($lockedStatus !== 'Active') throw new DomainException('Only an active group can start a freeze vote.', 409);

                $stmt = $pdo->prepare(
                    'SELECT role FROM group_members WHERE group_id = :group_id AND user_id = :user_id FOR UPDATE'
                );
                $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
                $lockedRole = $stmt->fetchColumn();
                if (!in_array($lockedRole, ['Main Operator', 'Co-Operator'], true)) {
                    throw new DomainException('Only a group operator can start a freeze vote.', 403);
                }

                $stmt = $pdo->prepare(
                    'SELECT poll_id FROM freeze_polls
                     WHERE group_id = :group_id AND poll_status = :active
                     ORDER BY poll_id DESC LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([':group_id' => $groupId, ':active' => 'Active']);
                if ($stmt->fetchColumn()) throw new DomainException('A freeze vote is already in progress for this group.', 409);

                $stmt = $pdo->prepare(
                    'SELECT user_id FROM group_members WHERE group_id = :group_id ORDER BY user_id FOR UPDATE'
                );
                $stmt->execute([':group_id' => $groupId]);
                $eligibleUsers = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                if (!$eligibleUsers || !in_array($userId, $eligibleUsers, true)) {
                    throw new DomainException('The group has no eligible members for a freeze vote.', 409);
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO freeze_polls (group_id, initiator_user_id, reason, poll_status)
                     VALUES (:group_id, :initiator, :reason, :status)'
                );
                $stmt->execute([
                    ':group_id' => $groupId,
                    ':initiator' => $userId,
                    ':reason' => $freezeReason,
                    ':status' => 'Active',
                ]);
                $pollId = (int) $pdo->lastInsertId();
                $memberInsert = $pdo->prepare(
                    'INSERT INTO freeze_poll_members (poll_id, user_id) VALUES (:poll_id, :user_id)'
                );
                foreach ($eligibleUsers as $eligibleUserId) {
                    $memberInsert->execute([':poll_id' => $pollId, ':user_id' => $eligibleUserId]);
                }
                $pdo->prepare(
                    'INSERT INTO audit_logs (group_id, actor_user_id, activity_type, details, verification_status)
                     VALUES (:group_id, :actor, :activity, :details, :verification)'
                )->execute([
                    ':group_id' => $groupId,
                    ':actor' => $userId,
                    ':activity' => 'Freeze Request Initiated',
                    ':details' => 'Freeze vote initiated by ' . $lockedRole . '. Reason: ' . $freezeReason,
                    ':verification' => 'Pending Member Vote',
                ]);
                $pdo->commit();
                $_SESSION['group_dashboard_flash'][$groupId] = 'Freeze request sent to all group members for a vote.';
                header('Location: group_dashboard.php?id=' . $groupId, true, 303);
                exit;
            } catch (DomainException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $actionMessage = $e->getMessage();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Freeze request error: ' . $e->getMessage());
                $actionMessage = 'The freeze request could not be created. Please try again.';
            }
        }
    } elseif ($action === 'unfreeze' && $canFreezeGroup) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT group_status FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
            );
            $stmt->execute([':group_id' => $groupId]);
            $lockedStatus = $stmt->fetchColumn();
            if ($lockedStatus !== 'Frozen') {
                throw new DomainException('This group is no longer frozen.', 409);
            }

            $stmt = $pdo->prepare(
                'SELECT role FROM group_members WHERE group_id = :group_id AND user_id = :user_id FOR UPDATE'
            );
            $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
            $lockedRole = $stmt->fetchColumn();
            if (!in_array($lockedRole, ['Main Operator', 'Co-Operator'], true)) {
                throw new DomainException('Only a group operator can unfreeze this group.', 403);
            }

            $stmt = $pdo->prepare(
                'UPDATE paluwagan_groups SET group_status = :active
                 WHERE group_id = :group_id AND group_status = :frozen'
            );
            $stmt->execute([':active' => 'Active', ':group_id' => $groupId, ':frozen' => 'Frozen']);
            if ($stmt->rowCount() !== 1) throw new DomainException('This group is no longer frozen.', 409);

            // Close any legacy pending poll so it cannot block new requests after reopening.
            $pdo->prepare(
                'UPDATE freeze_polls SET poll_status = :failed
                 WHERE group_id = :group_id AND poll_status = :active'
            )->execute([':failed' => 'Failed', ':group_id' => $groupId, ':active' => 'Active']);

            $pdo->prepare(
                'INSERT INTO audit_logs (group_id, actor_user_id, activity_type, details, verification_status)
                 VALUES (:group_id, :actor, :activity, :details, :verification)'
            )->execute([
                ':group_id' => $groupId,
                ':actor' => $userId,
                ':activity' => 'Group Unfrozen',
                ':details' => 'Group reopened by ' . $lockedRole . '.',
                ':verification' => 'Group Operator',
            ]);

            $pdo->commit();
            $_SESSION['group_dashboard_flash'][$groupId] = 'Group unfrozen. It is active again.';
            header('Location: group_dashboard.php?id=' . $groupId, true, 303);
            exit;
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $actionMessage = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Group unfreeze error: ' . $e->getMessage());
            $actionMessage = 'The group could not be unfrozen. Please try again.';
        }
    } elseif ($action === 'claim' && $canClaimPot) {
        try {
            tokaClaimCyclePot(
                $pdo, $groupId, (int) $currentRound['round_id'], $userId,
                (int) $currentMember['member_id']
            );
            $_SESSION['group_dashboard_flash'][$groupId] = 'Pot claim submitted. It is waiting for the '
                . $claimApproverRole . ' to approve it.';
            header('Location: group_dashboard.php?id=' . $groupId);
            exit;
        } catch (Throwable $e) {
            if ($e instanceof DomainException) {
                $actionMessage = $e->getMessage();
            } else {
                error_log('Pot claim error: ' . $e->getMessage());
                $actionMessage = 'The pot claim could not be saved. Please try again.';
            }
        }
    } elseif ($action === 'approve_claim' && $canApprovePotClaim) {
        try {
            $cycleCompleted = tokaApproveCyclePotClaim(
                $pdo, $groupId, (int) $currentRound['round_id'], $userId
            );
            $_SESSION['group_dashboard_flash'][$groupId] = $cycleCompleted
                ? 'The final pot claim was approved. This group cycle is complete.'
                : 'Pot claim approved. The next round has started.';
            header('Location: group_dashboard.php?id=' . $groupId);
            exit;
        } catch (Throwable $e) {
            if ($e instanceof DomainException) {
                $actionMessage = $e->getMessage();
            } else {
                error_log('Pot claim approval error: ' . $e->getMessage());
                $actionMessage = 'The pot claim approval could not be saved. Please try again.';
            }
        }
    } else {
        $actionMessage = 'This action is not available for your role or the current round.';
    }
}

$memberCount = (int) ($group['member_count'] ?? 0);
$slotCount = max(1, (int) ($group['total_member_slots'] ?? 1));
$roundCount = max($slotCount, count($rounds));
$roundNumber = (int) ($currentRound['round_number'] ?? 1);
$roundStatus = $currentRound['round_status'] ?? '';
$groupStatus = (string) ($group['group_status'] ?? 'Waiting');
$isOperator = ($currentMember['role'] ?? '') === 'Main Operator';
$isCoOperator = ($currentMember['role'] ?? '') === 'Co-Operator';
$isClaimApprover = ($claimApproverRole === 'Main Operator' && $isOperator)
    || ($claimApproverRole === 'Co-Operator' && $isCoOperator);
$canFreezeGroup = in_array($currentMember['role'] ?? '', ['Main Operator', 'Co-Operator'], true);
$isRecipient = $currentRound && (int) $currentRound['receiver_member_id'] === (int) ($currentMember['member_id'] ?? 0);
$hasDisbursed = $currentRound && $currentRound['actual_disbursement_date'] !== null;
$receiptClaimed = $currentRound && $currentRound['recipient_claimed_at'] !== null;
$potOperatorApproved = $currentRound && $currentRound['operator_approved_at'] !== null;
$allMembersVerified = $currentRound && $memberCount > 0
    && (int) $currentRound['verified_member_count'] >= $memberCount;
$pendingMemberCount = (int) ($currentRound['pending_member_count'] ?? 0);
$paymentGraceDeadline = $currentRound && !empty($currentRound['target_deadline'])
    ? tokaCycleGraceDeadline(new DateTimeImmutable($currentRound['target_deadline']))
    : null;
$paymentGraceExpired = $paymentGraceDeadline !== null && new DateTimeImmutable('now') >= $paymentGraceDeadline;
$claimPaymentConditionMet = $allMembersVerified || ($paymentGraceExpired && $pendingMemberCount === 0);
$canClaimPot = $groupStatus === 'Active' && $currentRound && $roundStatus === 'Ongoing'
    && $isRecipient && $claimPaymentConditionMet && !$receiptClaimed && !$potOperatorApproved;
$canApprovePotClaim = $groupStatus === 'Active' && $currentRound && $roundStatus === 'Ongoing'
    && $isClaimApprover && $receiptClaimed && !$potOperatorApproved;
$paymentCycleIsOpen = $groupStatus === 'Active' && $currentRound
    && $roundStatus === 'Ongoing' && !$hasDisbursed && !$receiptClaimed
    && !$paymentGraceExpired && count($channels) > 0;
$canSubmitPayment = $paymentCycleIsOpen && !$currentMemberPaymentLocked;
$paymentLateDays = 0;
if ($currentRound && !empty($currentRound['target_deadline'])) {
    try {
        $paymentCutoff = tokaCycleGraceDeadline(new DateTimeImmutable($currentRound['target_deadline']));
        $paymentSecondsLate = (new DateTimeImmutable('now'))->getTimestamp() - $paymentCutoff->getTimestamp();
        $paymentLateDays = $paymentSecondsLate > 0 ? (int) ceil($paymentSecondsLate / 86400) : 0;
    } catch (Throwable $e) {
        $paymentLateDays = 0;
    }
}
$paymentStatusLabel = $paymentLateDays > 0 ? 'Late (' . $paymentLateDays . ' day' . ($paymentLateDays === 1 ? '' : 's') . ')' : 'On-time';
$paymentTotal = (float) ($group['contribution_amount'] ?? 0)
    + ((float) ($group['late_fee_rate'] ?? 0) * $paymentLateDays);
$roundCollected = (float) ($currentRound['collected'] ?? 0);
$roundExpected = (float) ($group['contribution_amount'] ?? 0) * $slotCount;
$progressNote = 'Waiting for schedule setup';
if ($currentRound) {
    if ($receiptClaimed && !$potOperatorApproved) {
        $progressNote = 'Waiting for ' . $claimApproverRole . ' approval';
    } elseif ($canClaimPot) {
        $progressNote = $allMembersVerified
            ? 'All payments verified; waiting for recipient to claim the pot'
            : 'Payment window closed; recipient may claim the pot';
    } elseif ($allMembersVerified && !$receiptClaimed) {
        $progressNote = 'All payments verified; waiting for recipient to claim the pot';
    } elseif ($potOperatorApproved) {
        $progressNote = 'Pot approved and disbursed';
    } elseif (!empty($currentRound['target_deadline'])) {
        $progressNote = 'Deadline: ' . groupDate($currentRound['target_deadline']);
    } else {
        $progressNote = 'Round in progress';
    }
}
$collectionPercent = $roundExpected > 0 ? min(100, (int) round(100 * $roundCollected / $roundExpected)) : 0;
$dueAt = $currentRound['target_deadline'] ?? $group['first_payment_due'] ?? null;
$daysUntilDue = null;
if ($dueAt) {
    try {
        $today = new DateTimeImmutable('today');
        $dueDate = new DateTimeImmutable($dueAt);
        $daysUntilDue = (int) $today->diff($dueDate)->format('%r%a');
    } catch (Throwable $e) {
        $daysUntilDue = null;
    }
}
$operatorName = 'Not assigned';
foreach ($members as $member) {
    if ($member['role'] === 'Main Operator') $operatorName = $member['username'];
}
$roundSubtitle = 'Current Receiver: ' . ($currentRound['receiver_name'] ?? 'Not assigned');
if ($receiptClaimed && !$potOperatorApproved) {
    $roundSubtitle = 'Pot claim by ' . ($currentRound['receiver_name'] ?? 'the recipient')
        . ' is waiting for ' . $claimApproverRole . ' approval';
} elseif ($allMembersVerified && !$receiptClaimed) {
    $roundSubtitle = 'All payments are verified; ' . ($currentRound['receiver_name'] ?? 'the recipient') . ' can claim the pot';
} elseif ($potOperatorApproved) {
    $roundSubtitle = 'Pot received by ' . ($currentRound['receiver_name'] ?? 'the recipient');
} elseif (!$currentRound) {
    $roundSubtitle = 'The group round schedule has not been set yet.';
}
$statusVerified = ($currentMember['account_status'] ?? '') === 'Active';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $group ? h($group['group_name']) . ' - Toka Dashboard' : 'Group Dashboard - Toka' ?></title>
    <link rel="stylesheet" href="group_dashboard.css?v=<?= filemtime(__DIR__ . '/group_dashboard.css') ?>">
    <link rel="stylesheet" href="freeze_poll.css?v=<?= filemtime(__DIR__ . '/freeze_poll.css') ?>">
    <link rel="stylesheet" href="../assets/typography.css?v=<?= filemtime(__DIR__ . '/../assets/typography.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/svg+xml" href="../assets/toka_icon.svg">
</head>
<body>
<div class="dashboard-layout">
    <aside class="group-sidebar">
        <a class="brand" href="my_groups.php" aria-label="Toka home"><img src="../assets/Toka.svg" alt="Toka"></a>
        <nav class="side-nav" aria-label="Main navigation">
            <a href="my_groups.php">
                <span class="nav-symbol"><i class="fa-solid fa-border-all"></i></span> Dashboard
            </a>
            <?php if ($group): ?>
                <a class="selected" href="group_dashboard.php?id=<?= (int) $groupId ?>">
                    <span class="nav-symbol"><i class="fa-solid fa-users"></i></span> My Groups
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-profile">
            <span class="avatar"><?= h(strtoupper(substr($username, 0, 1))) ?></span>
            <span class="sidebar-username"><?= h($username) ?></span>
            <a class="logout-link" href="../LOGIN_PAGE/logout.php" aria-label="Log out" title="Log out">
                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            </a>
        </div>
    </aside>

    <main class="dashboard-main"<?php if ($group): ?> data-freeze-poll-watch data-group-id="<?= (int) $groupId ?>" data-active-poll-id="<?= $activeFreezePoll ? (int) $activeFreezePoll['poll_id'] : '' ?>" data-unread-outcome-id="<?= $unreadFreezeOutcome ? (int) $unreadFreezeOutcome['poll_id'] : '' ?>"<?php endif; ?>>
        <?php if ($loadError !== ''): ?>
            <div class="notice error-notice" role="alert"><?= h($loadError) ?></div>
        <?php elseif (!$group): ?>
            <section class="not-found">
                <h1>Group unavailable</h1>
                <p>It may have been removed, or your account may not be a member.</p>
                <a class="button button-primary" href="my_groups.php">Back to My Groups</a>
            </section>
        <?php else: ?>
            <section class="round-card" id="overview">
                <div class="round-main">
                    <div class="round-title-line">
                        <h1><?= h($group['group_name']) ?></h1>
                        <span class="round-badge">Round: <?= $roundNumber ?>/<?= $roundCount ?></span>
                        <?php if ($receiptClaimed && !$potOperatorApproved): ?>
                            <span class="round-status awaiting">Awaiting <?= h($claimApproverRole) ?> Approval</span>
                        <?php elseif ($canClaimPot || ($allMembersVerified && !$receiptClaimed)): ?>
                            <span class="round-status awaiting">Pot Ready to Claim</span>
                        <?php endif; ?>
                    </div>
                    <p class="round-subtitle"><?= h($roundSubtitle) ?></p>
                    <div class="collection-progress" role="progressbar" aria-label="Payments submitted this round"
                         aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $collectionPercent ?>">
                        <span style="width: <?= $collectionPercent ?>%"></span>
                    </div>
                    <p class="collection-caption">
                        <?= h(number_format($roundCollected, 2)) ?> / <?= h(number_format($roundExpected, 2)) ?> verified this round
                        <span> | </span><?= h($progressNote) ?>
                    </p>
                </div>
                <div class="round-meta">
                    <div><span>Due:</span><strong><?= $daysUntilDue === null ? 'Not set' : ($daysUntilDue < 0 ? 'Overdue' : 'in ' . $daysUntilDue . ' day' . ($daysUntilDue === 1 ? '' : 's')) ?></strong></div>
                    <?php if ($paymentGraceDeadline): ?>
                        <div><span>Payment window ends:</span><strong><?= h($paymentGraceDeadline->format('M j, Y g:i A')) ?></strong></div>
                    <?php endif; ?>
                    <div class="late-fee">Late Fee Rate: <?= '₱' . h(number_format((float) $group['late_fee_rate'], 2)) ?>
                        <div class="late-fee-help">
                            <button type="button" class="info-tip" aria-label="Late fee and payment points information" aria-describedby="late-fee-tooltip">i</button>
                            <div class="late-fee-tooltip" id="late-fee-tooltip" role="tooltip">
                                <span class="late-fee-tooltip-title">Late Fee Rate</span>
                                <p>Late fee charged for an overdue contribution.</p>
                                <ul class="late-fee-points">
                                    <li><strong class="on-time-points">+5 pts</strong> if paid on time</li>
                                    <li><strong>-10 pts</strong> if 1&ndash;2 days late</li>
                                    <li><strong>-25 pts</strong> if 3+ days late</li>
                                    <li><strong>-50 pts</strong> if missed payment completely</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="top-actions">
                        <a class="outline-button" href="group_logs.php?id=<?= (int) $groupId ?>">View Logs</a>
                        <a class="outline-button" href="group_details.php?id=<?= (int) $groupId ?>">View Details</a>
                    </div>
                </div>
            </section>

            <?php if ($activeFreezePoll): ?>
                <section class="freeze-poll-status-card" aria-labelledby="freeze-poll-status-heading"
                         data-freeze-progress data-poll-id="<?= (int) $activeFreezePoll['poll_id'] ?>"
                         data-approvals="<?= (int) $activeFreezePoll['approvals'] ?>"
                         data-rejections="<?= (int) $activeFreezePoll['rejections'] ?>"
                         data-eligible="<?= (int) $activeFreezePoll['eligible_count'] ?>">
                    <div class="freeze-poll-status-copy">
                        <span class="freeze-poll-kicker">Member vote in progress</span>
                        <h2 id="freeze-poll-status-heading">Freeze request for <?= h($group['group_name']) ?></h2>
                        <p><?= h($activeFreezePoll['reason']) ?></p>
                    </div>
                    <div class="freeze-poll-counts" aria-live="polite">
                        <span><strong data-freeze-approvals><?= (int) $activeFreezePoll['approvals'] ?></strong> Approvals</span>
                        <span><strong data-freeze-rejections><?= (int) $activeFreezePoll['rejections'] ?></strong> Rejections</span>
                        <span><strong data-freeze-pending><?= $freezePendingVotes ?></strong> Pending</span>
                    </div>
                    <p class="freeze-poll-threshold">More than half of <?= (int) $activeFreezePoll['eligible_count'] ?> eligible members must approve to freeze this group.</p>
                </section>
            <?php endif; ?>

            <div class="filter-row">
                <label class="visually-hidden" for="member-filter">Filter members by status</label>
                <select id="member-filter">
                    <option value="all">All</option>
                    <option value="verified">Verified</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="overdue">Overdue</option>
                    <option value="pending approval">Pending Approval</option>
                </select>
            </div>

            <?php if ($actionMessage !== '' && !$openPaymentDialog && $submittedActionNotice === ''): ?>
                <div class="action-notice" role="alert"><?= h($actionMessage) ?></div>
            <?php endif; ?>

            <section class="member-grid" id="members" aria-label="Group members">
                <?php foreach ($members as $member): ?>
                    <?php
                    $payment = $memberPayments[(int) $member['member_id']] ?? null;
                    $memberIsRecipient = $currentRound && (int) $currentRound['receiver_member_id'] === (int) $member['member_id'];
                    $isOverdue = ($daysUntilDue !== null && $daysUntilDue < 0);

                    $memberPaymentState = 'Unpaid';
                    $memberPaymentTone = 'unpaid';
                    $filterCategory = 'unpaid';

                    if ($memberIsRecipient && $receiptClaimed && !$potOperatorApproved) {
                        $memberPaymentState = 'Claim Awaiting Approval';
                        $memberPaymentTone = 'awaiting';
                        $filterCategory = 'pending approval';
                    } elseif ($memberIsRecipient && $canClaimPot) {
                        $memberPaymentState = 'Pot Ready to Claim';
                        $memberPaymentTone = 'awaiting';
                        $filterCategory = 'pending approval';
                    } elseif ($payment && $payment['payment_status'] === 'Verified') {
                        $memberPaymentState = 'Verified';
                        $memberPaymentTone = 'verified';
                        $filterCategory = 'verified';
                    } elseif ($payment && $payment['payment_status'] === 'Pending Verification'
                        && !empty($payment['is_cooperator_verified'])) {
                        $memberPaymentState = 'Pending Main Operator';
                        $memberPaymentTone = 'pending-operator';
                        $filterCategory = 'pending approval';
                    } elseif ($payment && $payment['payment_status'] === 'Pending Verification') {
                        $memberPaymentState = 'Pending Co-Operator';
                        $memberPaymentTone = 'awaiting';
                        $filterCategory = 'pending approval';
                    } elseif ($payment && $payment['payment_status'] === 'Overdue') {
                        $memberPaymentState = 'Overdue';
                        $memberPaymentTone = 'overdue';
                        $filterCategory = 'overdue';
                    } elseif ($isOverdue && (!$payment || in_array($payment['payment_status'], ['Unpaid', 'Rejected'], true))) {
                        $memberPaymentState = 'Overdue';
                        $memberPaymentTone = 'overdue';
                        $filterCategory = 'overdue';
                    } else {
                        $memberPaymentState = 'Unpaid';
                        $memberPaymentTone = 'unpaid';
                        $filterCategory = 'unpaid';
                    }

                    $paymentNeedsCoOperator = $payment && $payment['payment_status'] === 'Pending Verification'
                        && empty($payment['is_cooperator_verified']);
                    $paymentNeedsMainOperator = $payment && $payment['payment_status'] === 'Pending Verification'
                        && !empty($payment['is_cooperator_verified']) && empty($payment['is_operator_verified']);
                    $canReviewMemberPayment = $groupStatus === 'Active' && $currentRound
                        && $roundStatus === 'Ongoing' && !$hasDisbursed && !$receiptClaimed
                        && (($isCoOperator && $paymentNeedsCoOperator) || ($isOperator && $paymentNeedsMainOperator));

                    $memberIcon = 'fa-solid fa-user';
                    if ($member['role'] === 'Main Operator') {
                        $memberIcon = 'fa-solid fa-crown';
                    } elseif ($member['role'] === 'Co-Operator') {
                        $memberIcon = 'fa-solid fa-user-shield';
                    }
                    ?>
                    <article class="member-card" data-role="<?= h($member['role']) ?>" data-status="<?= h($filterCategory) ?>">
                        <div class="member-left">
                            <span class="member-icon <?= $member['role'] === 'Main Operator' ? 'crown' : ($member['role'] === 'Co-Operator' ? 'shield' : '') ?>">
                                <i class="<?= $memberIcon ?>"></i>
                            </span>
                            <div class="member-description">
                                <div class="member-name-line">
                                    <strong><?= h($member['username']) ?></strong>
                                    <span class="payment-state <?= h($memberPaymentTone) ?>"><?= h($memberPaymentState) ?></span>
                                    <?php if ($member['account_status'] !== 'Active'): ?><span class="account-badge"><?= h($member['account_status']) ?></span><?php endif; ?>
                                </div>
                                <span class="member-role"><?= h($member['role']) ?></span>
                                <?php if ($payment && in_array($payment['payment_status'], ['Pending Verification', 'Verified'], true)): ?>
                                    <span class="payment-detail">Paid via: <?= h($payment['channel_type'] ?: 'Payment channel') ?> | Txn #: <?= h($payment['transaction_no'] ?: 'N/A') ?></span>
                                <?php elseif ($filterCategory === 'overdue'): ?>
                                    <span class="payment-detail overdue-text">Contribution is overdue</span>
                                <?php elseif ($filterCategory === 'unpaid'): ?>
                                    <span class="payment-detail">No payment submitted yet</span>
                                <?php endif; ?>
                                <?php if ($canReviewMemberPayment): ?>
                                    <button class="payment-review-trigger" type="button" data-open-payment-review
                                            data-payment-id="<?= (int) $payment['payment_id'] ?>"
                                            data-member-name="<?= h($member['username']) ?>"
                                            data-payment-channel="<?= h($payment['channel_type'] ?: 'Payment channel') ?>"
                                            data-transaction-number="<?= h($payment['transaction_no'] ?: 'N/A') ?>"
                                            data-payment-amount="PHP <?= h(number_format((float) $payment['amount_due'] + (float) $payment['late_fee_applied'], 2)) ?>">
                                        Review Payment
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="member-right">
                            <button type="button" class="trust-score-trigger"
                                    aria-label="<?= h($member['username'] . "'s trust score: " . (int) $member['trust_score'] . ' out of 100') ?>"
                                    aria-describedby="trust-score-tooltip-<?= (int) $member['member_id'] ?>">Trust: <?= (int) $member['trust_score'] ?></button>
                            <span class="slot-badge">Slot #<?= (int) $member['assigned_slot_number'] ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="empty-member-filter" id="empty-member-filter" hidden>
                    No members found with status "<span id="empty-filter-status"></span>".
                </div>
            </section>

            <?php foreach ($members as $member): ?>
                <div class="trust-score-tooltip" id="trust-score-tooltip-<?= (int) $member['member_id'] ?>" role="tooltip">
                    <span class="trust-score-tooltip-title"><?= h($member['username'] . "'s Score History") ?></span>
                    <p class="trust-score-current">Current: <strong><?= (int) $member['trust_score'] ?>/100</strong></p>
                    <?php if ($trustScoreHistoryUnavailable): ?>
                        <p class="trust-score-empty">Score history is currently unavailable.</p>
                    <?php elseif (!empty($trustScoreHistory[(int) $member['member_id']])): ?>
                        <p class="trust-score-history-label">Recent changes in this group</p>
                        <ul class="trust-score-history">
                            <?php foreach ($trustScoreHistory[(int) $member['member_id']] as $scoreEntry): ?>
                                <li>Round <?= (int) $scoreEntry['round_number'] ?>:
                                    <strong class="<?= $scoreEntry['adjustment'] > 0 ? 'trust-score-gain' : 'trust-score-loss' ?>"><?= $scoreEntry['adjustment'] > 0 ? '+' : '' ?><?= (int) $scoreEntry['adjustment'] ?> pts</strong>
                                    (<?= h($scoreEntry['reason']) ?>)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="trust-score-empty">No score changes recorded in this group.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($isOperator && $pendingRequests): ?>
                <dialog class="join-requests-dialog" id="join-requests-dialog">
                    <div class="join-requests-heading">
                        <div><h2>Manage Join Requests</h2>
                            <p>Review pending applicants for Group: <strong><?= h($group['group_name']) ?></strong>
                                (<?= (int) $group['member_count'] ?>/<?= (int) $group['total_member_slots'] ?> slots filled)</p>
                        </div>
                        <button type="button" class="join-requests-close" data-close-requests aria-label="Close">×</button>
                    </div>
                    <div class="join-requests-list">
                        <?php foreach ($pendingRequests as $request): ?>
                            <article class="join-request-row">
                                <span class="join-request-avatar"><?= h(strtoupper(substr($request['username'], 0, 2))) ?></span>
                                <div class="join-request-user"><strong><?= h($request['username']) ?></strong><span><?= h($request['age']) ?></span></div>
                                <?php if ($request['suggested_slot'] > 0): ?>
                                    <span class="join-request-slot">Slot: <?= (int) $request['suggested_slot'] ?>/<?= (int) $group['total_member_slots'] ?></span>
                                <?php else: ?>
                                    <span class="join-request-slot full-slot">No slots left</span>
                                <?php endif; ?>
                                <form method="post" class="join-request-decision">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                                    <input type="hidden" name="action" value="approve_join">
                                    <button class="approve-request" type="submit" <?= $request['suggested_slot'] < 1 ? 'disabled' : '' ?>>Approve</button>
                                </form>
                                <form method="post" class="join-request-decision">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                                    <input type="hidden" name="action" value="reject_join">
                                    <button class="reject-request" type="submit">Reject</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="join-requests-footer">
                        <span><strong><?= count($pendingRequests) ?></strong> pending request<?= count($pendingRequests) === 1 ? '' : 's' ?> remain</span>
                        <button type="button" class="join-requests-done" data-close-requests>Done</button>
                    </div>
                </dialog>
            <?php endif; ?>

            <section class="operator-bar">
                <div class="operator-identity">
                    <span class="operator-mark"><i class="<?= $isOperator ? 'fa-solid fa-crown' : ($isCoOperator ? 'fa-solid fa-user-shield' : 'fa-solid fa-user') ?>"></i></span>
                    <div>
                        <strong>Username: <?= h($username) ?></strong>
                        <span>Role: <?= h($currentMember['role'] ?? 'Member') ?></span>
                    </div>
                </div>
                <div class="operator-actions">
                    <div class="payment-action">
                        <?php if ($canSubmitPayment): ?>
                            <button class="operator-button pay-button" type="button" data-open-payment>Submit Pay</button>
                        <?php elseif ($currentMemberPaymentLocked): ?>
                            <button class="operator-button muted-button" type="button" disabled aria-describedby="payment-submitted-message">Submit Pay</button>
                            <span class="payment-submitted-message" id="payment-submitted-message" role="status">You already submitted your payment for this cycle.</span>
                        <?php elseif ($paymentGraceExpired): ?>
                            <button class="operator-button muted-button" type="button" disabled aria-describedby="payment-window-ended-message">Submit Pay</button>
                            <span class="payment-submitted-message" id="payment-window-ended-message" role="status">The three-day payment window has ended.</span>
                        <?php else: ?>
                            <button class="operator-button muted-button" type="button" disabled>Submit Pay</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($canFreezeGroup && $groupStatus === 'Active'): ?>
                        <?php if ($activeFreezePoll): ?>
                            <button class="operator-button muted-button" type="button" disabled>Freeze Vote In Progress</button>
                        <?php else: ?>
                            <?= actionButton('Freeze Group', true, 'freeze', $csrfToken) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($isOperator && $pendingRequests): ?>
                        <button class="operator-button action-button" type="button" data-open-requests>
                            Join Requests (<?= count($pendingRequests) ?>)
                        </button>
                    <?php endif; ?>
                    <?php if ($isClaimApprover): ?>
                        <?php if ($canApprovePotClaim): ?>
                            <?= actionButton('Approve Pot Claim', true, 'approve_claim', $csrfToken) ?>
                        <?php else: ?>
                            <button class="operator-button muted-button" type="button" disabled>Approve Pot Claim</button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($canFreezeGroup && $groupStatus === 'Frozen'): ?>
                        <?= actionButton('Unfreeze Group', true, 'unfreeze', $csrfToken) ?>
                    <?php endif; ?>
                    <?php if ($canClaimPot): ?>
                        <?= actionButton('Claim Pot', true, 'claim', $csrfToken) ?>
                    <?php else: ?>
                        <button class="operator-button muted-button" type="button" disabled>Claim Pot</button>
                    <?php endif; ?>
                </div>
            </section>

            <dialog class="dashboard-dialog action-confirm-dialog" id="action-confirm-dialog"
                    aria-labelledby="action-confirm-title" aria-describedby="action-confirm-message">
                <h2 id="action-confirm-title">Confirm this action</h2>
                <p id="action-confirm-message"></p>
                <div class="freeze-reason-field" id="freeze-reason-field" hidden>
                    <label for="freeze-reason-select">What's the Reason?</label>
                    <select id="freeze-reason-select" autocomplete="off">
                        <option value="">Select a reason</option>
                        <?php foreach ($freezeReasonOptions as $reasonOption): ?>
                            <option value="<?= h($reasonOption) ?>"><?= h($reasonOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="freeze-custom-reason" hidden>
                        <label for="freeze-reason-input">Reason details</label>
                        <textarea id="freeze-reason-input" autocomplete="off" maxlength="128" rows="3" placeholder="Explain why the group should be frozen."></textarea>
                        <small>Required, up to 128 characters.</small>
                    </div>
                </div>
                <div class="payment-dialog-actions">
                    <button class="payment-back" type="button" data-action-cancel>Cancel</button>
                    <button class="payment-confirm" type="button" data-action-confirm>Confirm</button>
                </div>
            </dialog>

            <?php if ($freezeVotePopup): ?>
                <dialog class="dashboard-dialog freeze-vote-dialog" id="freeze-vote-dialog" aria-labelledby="freeze-vote-title">
                    <span class="freeze-poll-kicker">Member vote required</span>
                    <h2 id="freeze-vote-title">Freeze request for <?= h($group['group_name']) ?></h2>
                    <dl class="freeze-request-details">
                        <div><dt>Initiated by</dt><dd><?= h($freezeVotePopup['initiator_name']) ?></dd></div>
                        <div><dt>Reason</dt><dd><?= h($freezeVotePopup['reason']) ?></dd></div>
                        <div><dt>Requested</dt><dd><?= h(groupDate($freezeVotePopup['created_at'], 'M j, Y g:i A')) ?></dd></div>
                    </dl>
                    <div class="freeze-poll-counts dialog-freeze-counts" data-freeze-progress data-poll-id="<?= (int) $freezeVotePopup['poll_id'] ?>" aria-live="polite">
                        <span><strong data-freeze-approvals><?= (int) $freezeVotePopup['approvals'] ?></strong> Approvals</span>
                        <span><strong data-freeze-rejections><?= (int) $freezeVotePopup['rejections'] ?></strong> Rejections</span>
                        <span><strong data-freeze-pending><?= max(0, (int) $freezeVotePopup['eligible_count'] - (int) $freezeVotePopup['approvals'] - (int) $freezeVotePopup['rejections']) ?></strong> Pending</span>
                    </div>
                    <p class="freeze-vote-note">The group stays active while members vote. Your vote is final and cannot be changed.</p>
                    <form method="post" action="freeze_poll_action.php" class="freeze-vote-actions">
                        <input type="hidden" name="csrf_token" value="<?= h($freezePollToken) ?>">
                        <input type="hidden" name="poll_action" value="vote">
                        <input type="hidden" name="poll_id" value="<?= (int) $freezeVotePopup['poll_id'] ?>">
                        <button class="freeze-vote-reject" type="submit" name="vote_choice" value="No">Reject Freeze</button>
                        <button class="freeze-vote-approve" type="submit" name="vote_choice" value="Yes">Approve Freeze</button>
                    </form>
                    <button type="button" class="freeze-vote-later" data-close-freeze-vote>Decide later</button>
                </dialog>
            <?php elseif ($freezeOutcomePopup): ?>
                <dialog class="dashboard-dialog freeze-vote-dialog" id="freeze-outcome-dialog" aria-labelledby="freeze-outcome-title">
                    <span class="freeze-poll-kicker">Freeze vote result</span>
                    <h2 id="freeze-outcome-title"><?= h($freezeOutcomeTitle) ?></h2>
                    <p class="freeze-outcome-copy"><strong><?= h($freezeOutcomePopup['group_name']) ?></strong>: <?= h($freezeOutcomeMessage) ?></p>
                    <dl class="freeze-request-details">
                        <div><dt>Initiated by</dt><dd><?= h($freezeOutcomePopup['initiator_name']) ?></dd></div>
                        <div><dt>Reason</dt><dd><?= h($freezeOutcomePopup['reason']) ?></dd></div>
                        <div><dt>Requested</dt><dd><?= h(groupDate($freezeOutcomePopup['created_at'], 'M j, Y g:i A')) ?></dd></div>
                    </dl>
                    <div class="freeze-poll-counts dialog-freeze-counts">
                        <span><strong><?= (int) $freezeOutcomePopup['approvals'] ?></strong> Approvals</span>
                        <span><strong><?= (int) $freezeOutcomePopup['rejections'] ?></strong> Rejections</span>
                        <span><strong><?= max(0, (int) $freezeOutcomePopup['eligible_count'] - (int) $freezeOutcomePopup['approvals'] - (int) $freezeOutcomePopup['rejections']) ?></strong> Pending</span>
                    </div>
                    <form method="post" action="freeze_poll_action.php" class="freeze-outcome-actions">
                        <input type="hidden" name="csrf_token" value="<?= h($freezePollToken) ?>">
                        <input type="hidden" name="poll_action" value="ack_outcome">
                        <input type="hidden" name="poll_id" value="<?= (int) $freezeOutcomePopup['poll_id'] ?>">
                        <button class="freeze-vote-approve" type="submit">Got it</button>
                    </form>
                </dialog>
            <?php endif; ?>

            <?php if ($isOperator || $isCoOperator): ?>
                <dialog class="dashboard-dialog payment-review-dialog" id="payment-review-dialog">
                    <h2>Verify Payment</h2>
                    <p class="payment-review-intro">Review <strong data-review-member></strong>'s submission before verifying.</p>
                    <section class="payment-review-panel" aria-label="Payment details">
                        <h3>PAYMENT DETAILS</h3>
                        <dl class="dialog-details">
                            <div><dt>Member</dt><dd data-review-member-detail></dd></div>
                            <div><dt>Payment Channel</dt><dd data-review-channel></dd></div>
                            <div><dt>Transaction No.</dt><dd data-review-transaction></dd></div>
                            <div><dt>Amount</dt><dd data-review-amount></dd></div>
                        </dl>
                    </section>
                    <form method="post" class="payment-review-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="review_payment">
                        <input type="hidden" name="payment_id" data-review-payment-id>
                        <div class="payment-dialog-actions">
                            <button class="payment-review-reject" type="submit" name="decision" value="reject">Reject</button>
                            <button class="payment-confirm" type="submit" name="decision" value="approve">Verify Payment</button>
                        </div>
                    </form>
                </dialog>
            <?php endif; ?>

            <?php if ($canSubmitPayment || $openPaymentDialog): ?>
                <dialog class="dashboard-dialog payment-dialog" id="payment-dialog">
                    <h2>Ready to submit your<br>payment?</h2>
                    <?php if ($actionMessage !== '' && $openPaymentDialog): ?>
                        <p class="payment-dialog-error" role="alert"><?= h($actionMessage) ?></p>
                    <?php endif; ?>
                    <form method="post" class="payment-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="submit_payment">
                        <div class="payment-form-row">
                            <label>Status
                                <input type="text" autocomplete="off" value="<?= h($paymentStatusLabel) ?>" readonly>
                            </label>
                            <label>Amount to Pay
                                <input type="text" autocomplete="off" value="₱<?= h(number_format($paymentTotal, 2)) ?>" readonly>
                            </label>
                        </div>
                        <label>Payment Channel
                            <select name="channel_id" required <?= $channels ? '' : 'disabled' ?>>
                                <option value="" disabled <?= $paymentFormState['channel_id'] === '' ? 'selected' : '' ?>>Choose a channel</option>
                                <?php foreach ($channels as $channel): ?>
                                    <option value="<?= (int) $channel['channel_id'] ?>"
                                        <?= (string) $channel['channel_id'] === $paymentFormState['channel_id'] ? 'selected' : '' ?>>
                                        <?= h($channel['channel_type'] ?: 'Other') ?><?= $channel['account_name'] ? ' — ' . h($channel['account_name']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Transaction No.
                            <input type="text" name="transaction_no" autocomplete="off" maxlength="100" required
                                   placeholder="Enter Transaction No."
                                   value="<?= h($paymentFormState['transaction_no']) ?>">
                        </label>
                        <div class="payment-dialog-actions">
                            <button type="button" class="payment-back" data-close-payment>Back</button>
                            <button type="submit" class="payment-confirm" <?= $canSubmitPayment ? '' : 'disabled' ?>>Confirm</button>
                        </div>
                    </form>
                </dialog>
            <?php endif; ?>

            <?php if ($submittedActionNotice !== ''): ?>
                <dialog class="dashboard-dialog submitted-action-dialog" id="submitted-action-dialog" aria-labelledby="submitted-action-title">
                    <h2 id="submitted-action-title"><?= h($submittedActionNotice) ?></h2>
                    <div class="payment-dialog-actions">
                        <button class="payment-confirm" type="button" data-close-submitted-action>OK</button>
                    </div>
                </dialog>
            <?php endif; ?>


        <?php endif; ?>
    </main>
</div>
<script>
    const lateFeeHelp = document.querySelector('.late-fee-help');
    const lateFeeTooltip = document.getElementById('late-fee-tooltip');
    function positionLateFeeTooltip() {
        if (!lateFeeHelp || !lateFeeTooltip) return;
        const tooltipRight = lateFeeHelp.getBoundingClientRect().right;
        const tooltipLeft = tooltipRight - lateFeeTooltip.offsetWidth;
        const viewportWidth = document.documentElement.clientWidth;
        const offset = tooltipLeft < 12 ? 12 - tooltipLeft
            : tooltipRight > viewportWidth - 12 ? viewportWidth - 12 - tooltipRight : 0;
        lateFeeTooltip.style.setProperty('--tooltip-offset', `${offset}px`);
    }
    function showLateFeeTooltip() {
        if (!lateFeeHelp) return;
        delete lateFeeHelp.dataset.dismissed;
        positionLateFeeTooltip();
    }
    lateFeeHelp?.addEventListener('mouseenter', showLateFeeTooltip);
    lateFeeHelp?.addEventListener('focusin', showLateFeeTooltip);
    lateFeeHelp?.querySelector('.info-tip')?.addEventListener('click', event => {
        event.currentTarget.focus();
        showLateFeeTooltip();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && lateFeeHelp?.matches(':hover, :focus-within')) {
            lateFeeHelp.dataset.dismissed = 'true';
        }
    });
    window.addEventListener('resize', positionLateFeeTooltip);

    let activeTrustTrigger = null;
    let activeTrustTooltip = null;
    let trustTooltipCloseTimer;
    function positionTrustTooltip() {
        if (!activeTrustTrigger || !activeTrustTooltip) return;
        const triggerBounds = activeTrustTrigger.getBoundingClientRect();
        const width = activeTrustTooltip.offsetWidth;
        const height = activeTrustTooltip.offsetHeight;
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = window.innerHeight;
        const left = Math.max(12, Math.min(triggerBounds.right - width, viewportWidth - width - 12));
        const below = triggerBounds.bottom + 8;
        const top = below + height <= viewportHeight - 12 ? below : Math.max(12, triggerBounds.top - height - 8);
        activeTrustTooltip.style.left = `${left}px`;
        activeTrustTooltip.style.top = `${top}px`;
    }
    function hideTrustTooltip() {
        window.clearTimeout(trustTooltipCloseTimer);
        if (activeTrustTooltip) delete activeTrustTooltip.dataset.open;
        activeTrustTrigger = null;
        activeTrustTooltip = null;
    }
    function showTrustTooltip(trigger) {
        window.clearTimeout(trustTooltipCloseTimer);
        if (activeTrustTrigger !== trigger) hideTrustTooltip();
        activeTrustTrigger = trigger;
        activeTrustTooltip = document.getElementById(trigger.getAttribute('aria-describedby'));
        if (!activeTrustTooltip) return;
        positionTrustTooltip();
        activeTrustTooltip.dataset.open = 'true';
    }
    function scheduleTrustTooltipClose() {
        window.clearTimeout(trustTooltipCloseTimer);
        trustTooltipCloseTimer = window.setTimeout(() => {
            if (!activeTrustTrigger?.matches(':hover, :focus') && !activeTrustTooltip?.matches(':hover')) {
                hideTrustTooltip();
            }
        }, 120);
    }
    document.querySelectorAll('.trust-score-trigger').forEach(trigger => {
        const tooltip = document.getElementById(trigger.getAttribute('aria-describedby'));
        trigger.addEventListener('mouseenter', () => showTrustTooltip(trigger));
        trigger.addEventListener('focus', () => showTrustTooltip(trigger));
        trigger.addEventListener('click', () => { trigger.focus(); showTrustTooltip(trigger); });
        trigger.addEventListener('mouseleave', scheduleTrustTooltipClose);
        trigger.addEventListener('blur', scheduleTrustTooltipClose);
        tooltip?.addEventListener('mouseenter', () => window.clearTimeout(trustTooltipCloseTimer));
        tooltip?.addEventListener('mouseleave', scheduleTrustTooltipClose);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') hideTrustTooltip();
    });
    document.addEventListener('pointerdown', event => {
        if (!activeTrustTrigger?.contains(event.target) && !activeTrustTooltip?.contains(event.target)) {
            hideTrustTooltip();
        }
    });
    window.addEventListener('resize', positionTrustTooltip);
    window.addEventListener('scroll', positionTrustTooltip, true);
    document.getElementById('member-filter')?.addEventListener('change', hideTrustTooltip);

    const requestsDialog = document.getElementById('join-requests-dialog');
    document.querySelector('[data-open-requests]')?.addEventListener('click', () => requestsDialog?.showModal());
    requestsDialog?.querySelectorAll('[data-close-requests]').forEach(button => {
        button.addEventListener('click', () => requestsDialog.close());
    });

    const paymentDialog = document.getElementById('payment-dialog');
    document.querySelector('[data-open-payment]')?.addEventListener('click', () => paymentDialog?.showModal());
    paymentDialog?.querySelectorAll('[data-close-payment]').forEach(button => {
        button.addEventListener('click', () => paymentDialog.close());
    });
    <?php if ($openPaymentDialog): ?>
        paymentDialog?.showModal();
    <?php endif; ?>

    const submittedActionDialog = document.getElementById('submitted-action-dialog');
    if (submittedActionDialog) {
        submittedActionDialog.showModal();
        submittedActionDialog.querySelector('[data-close-submitted-action]')?.addEventListener('click', () => submittedActionDialog.close());
    }

    const paymentReviewDialog = document.getElementById('payment-review-dialog');
    document.querySelectorAll('[data-open-payment-review]').forEach(button => {
        button.addEventListener('click', () => {
            if (!paymentReviewDialog) return;
            paymentReviewDialog.querySelector('[data-review-payment-id]').value = button.dataset.paymentId || '';
            paymentReviewDialog.querySelector('[data-review-member]').textContent = button.dataset.memberName || '';
            paymentReviewDialog.querySelector('[data-review-member-detail]').textContent = button.dataset.memberName || '';
            paymentReviewDialog.querySelector('[data-review-channel]').textContent = button.dataset.paymentChannel || '';
            paymentReviewDialog.querySelector('[data-review-transaction]').textContent = button.dataset.transactionNumber || '';
            paymentReviewDialog.querySelector('[data-review-amount]').textContent = button.dataset.paymentAmount || '';
            paymentReviewDialog.showModal();
        });
    });
    paymentReviewDialog?.addEventListener('click', event => {
        if (event.target === paymentReviewDialog) paymentReviewDialog.close();
    });

    const actionConfirmDialog = document.getElementById('action-confirm-dialog');
    const actionConfirmTitle = document.getElementById('action-confirm-title');
    const actionConfirmMessage = document.getElementById('action-confirm-message');
    const actionConfirmButton = actionConfirmDialog?.querySelector('[data-action-confirm]');
    const actionCancelButton = actionConfirmDialog?.querySelector('[data-action-cancel]');
    const freezeReasonField = document.getElementById('freeze-reason-field');
    const freezeReasonSelect = document.getElementById('freeze-reason-select');
    const freezeCustomReason = document.getElementById('freeze-custom-reason');
    const freezeReasonInput = document.getElementById('freeze-reason-input');
    let pendingActionForm = null;

    const actionConfirmCopy = {
        claim: {
            title: 'Confirm pot claim?',
            message: 'Confirm that you received the pot? Your claim will be sent to the <?= h($claimApproverRole) ?>. The next round starts after approval.',
            button: 'Confirm Claim'
        },
        approve_claim: {
            title: 'Approve pot claim?',
            message: 'Approving confirms this round’s pot was disbursed and moves the group to the next round. If this is the final round, the group will be marked complete.',
            button: 'Approve Claim'
        },
        freeze: {
            title: 'Request a group freeze?',
            message: 'The group will remain active while every eligible member is notified and votes on this request.',
            button: 'Send Freeze Request'
        },
        unfreeze: {
            title: 'Unfreeze this group?',
            message: 'The group will return to Active so operators and members can continue using it.',
            button: 'Unfreeze Group'
        }
    };

    document.querySelectorAll('.action-form').forEach(form => {
        form.addEventListener('submit', event => {
            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;
                return;
            }
            if (!actionConfirmDialog) return;

            event.preventDefault();
            const action = form.querySelector('[name="action"]')?.value || '';
            const copy = actionConfirmCopy[action] || {
                title: 'Confirm this action?',
                message: 'Are you sure you want to continue?',
                button: 'Confirm'
            };
            actionConfirmTitle.textContent = copy.title;
            actionConfirmMessage.textContent = copy.message;
            actionConfirmButton.textContent = copy.button;
            const isFreezeRequest = action === 'freeze';
            if (freezeReasonField) freezeReasonField.hidden = !isFreezeRequest;
            if (freezeReasonSelect) {
                freezeReasonSelect.value = '';
                freezeReasonSelect.setCustomValidity('');
            }
            if (freezeCustomReason) freezeCustomReason.hidden = true;
            if (freezeReasonInput) {
                freezeReasonInput.required = false;
                freezeReasonInput.value = '';
                freezeReasonInput.setCustomValidity('');
            }
            pendingActionForm = form;
            actionConfirmDialog.showModal();
            if (isFreezeRequest) freezeReasonSelect?.focus();
            else actionCancelButton?.focus();
        });
    });

    freezeReasonSelect?.addEventListener('change', () => {
        freezeReasonSelect.setCustomValidity('');
        const needsCustomReason = freezeReasonSelect.value === 'Other/Manual Dispute';
        if (freezeCustomReason) freezeCustomReason.hidden = !needsCustomReason;
        if (freezeReasonInput) {
            freezeReasonInput.required = needsCustomReason;
            freezeReasonInput.setCustomValidity('');
            if (!needsCustomReason) freezeReasonInput.value = '';
        }
        if (needsCustomReason) freezeReasonInput?.focus();
    });

    freezeReasonInput?.addEventListener('input', () => freezeReasonInput.setCustomValidity(''));

    actionCancelButton?.addEventListener('click', () => {
        pendingActionForm = null;
        actionConfirmDialog.close();
    });
    actionConfirmButton?.addEventListener('click', () => {
        const form = pendingActionForm;
        const action = form?.querySelector('[name="action"]')?.value || '';
        if (action === 'freeze') {
            const selectedReason = freezeReasonSelect?.value || '';
            if (!selectedReason) {
                freezeReasonSelect?.setCustomValidity('Choose a reason for requesting the freeze.');
                freezeReasonSelect?.reportValidity();
                return;
            }
            let reason = selectedReason;
            if (selectedReason === 'Other/Manual Dispute') {
                const details = freezeReasonInput?.value.trim().replace(/\s+/g, ' ') || '';
                if (!details) {
                    freezeReasonInput?.setCustomValidity('Enter details for the Other/Manual Dispute reason.');
                    freezeReasonInput?.reportValidity();
                    return;
                }
                reason = `${selectedReason}: ${details}`;
            }
            const hiddenReason = form.querySelector('[name="reason"]');
            if (hiddenReason) hiddenReason.value = reason;
        }
        pendingActionForm = null;
        actionConfirmDialog.close();
        if (!form) return;
        form.dataset.confirmed = 'true';
        form.requestSubmit();
    });
    actionConfirmDialog?.addEventListener('cancel', () => {
        pendingActionForm = null;
    });
    actionConfirmDialog?.addEventListener('click', event => {
        if (event.target === actionConfirmDialog) {
            pendingActionForm = null;
            actionConfirmDialog.close();
        }
    });

    const freezeVoteDialog = document.getElementById('freeze-vote-dialog');
    const freezeOutcomeDialog = document.getElementById('freeze-outcome-dialog');
    const freezeNoticeDialog = freezeVoteDialog || freezeOutcomeDialog;
    const openFreezeNotice = () => {
        if (!freezeNoticeDialog || freezeNoticeDialog.open) return;
        const otherDialog = Array.from(document.querySelectorAll('dialog[open]'))
            .find(dialog => dialog !== freezeNoticeDialog);
        if (otherDialog) {
            otherDialog.addEventListener('close', () => window.setTimeout(openFreezeNotice, 0), { once: true });
            return;
        }
        freezeNoticeDialog.showModal();
    };
    openFreezeNotice();
    document.querySelector('[data-close-freeze-vote]')?.addEventListener('click', () => freezeVoteDialog?.close());

    const freezePollWatch = document.querySelector('[data-freeze-poll-watch]');
    if (freezePollWatch) {
        const refreshFreezePoll = async () => {
            try {
                const response = await fetch(window.tokaTabAuthUrl(`freeze_poll_status.php?group_id=${encodeURIComponent(freezePollWatch.dataset.groupId || '')}`), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' }
                });
                if (!response.ok) return;
                const data = await response.json();
                const activePoll = data.active_poll;
                const displayedPollId = freezePollWatch.dataset.activePollId || '';
                if (activePoll && String(activePoll.poll_id) !== displayedPollId) {
                    window.tokaTabReload();
                    return;
                }
                if (!activePoll && displayedPollId) {
                    window.tokaTabReload();
                    return;
                }
                if (activePoll) {
                    document.querySelectorAll(`[data-freeze-progress][data-poll-id="${activePoll.poll_id}"]`).forEach(card => {
                        const pending = Math.max(0, activePoll.eligible_count - activePoll.approvals - activePoll.rejections);
                        card.querySelector('[data-freeze-approvals]')?.replaceChildren(String(activePoll.approvals));
                        card.querySelector('[data-freeze-rejections]')?.replaceChildren(String(activePoll.rejections));
                        card.querySelector('[data-freeze-pending]')?.replaceChildren(String(pending));
                    });
                }
                const unreadOutcomeId = data.unread_outcome_id ? String(data.unread_outcome_id) : '';
                if (unreadOutcomeId && unreadOutcomeId !== (freezePollWatch.dataset.unreadOutcomeId || '')) {
                    window.tokaTabReload();
                }
            } catch (error) {
                // Keep the current page usable if a brief status check fails.
            }
        };
        window.setInterval(refreshFreezePoll, 12000);
    }


    const memberFilter = document.getElementById('member-filter');
    const emptyMessage = document.getElementById('empty-member-filter');
    const emptyFilterStatus = document.getElementById('empty-filter-status');

    function applyMemberFilter() {
        if (!memberFilter) return;
        const selected = memberFilter.value.toLowerCase().trim();
        const visibleCards = [];

        document.querySelectorAll('.member-card').forEach(card => {
            const cardStatus = (card.dataset.status || '').toLowerCase().trim();
            const isVisible = selected === 'all' || cardStatus === selected;
            card.hidden = !isVisible;
            if (isVisible) {
                visibleCards.push(card);
            } else {
                card.classList.remove('card-odd', 'card-even', 'card-last-row', 'card-full-row');
            }
        });

        visibleCards.forEach((card, index) => {
            const isSingle = visibleCards.length === 1;
            card.classList.toggle('card-odd', !isSingle && index % 2 === 0);
            card.classList.toggle('card-even', isSingle || index % 2 === 1);

            const total = visibleCards.length;
            const isLastRow = total % 2 === 0
                ? (index >= total - 2)
                : (index === total - 1);
            card.classList.toggle('card-last-row', isLastRow);
            card.classList.toggle('card-full-row', total % 2 === 1 && index === total - 1);
        });

        if (emptyMessage) {
            emptyMessage.hidden = visibleCards.length > 0;
            if (emptyFilterStatus && memberFilter.selectedIndex >= 0) {
                emptyFilterStatus.textContent = memberFilter.options[memberFilter.selectedIndex].text;
            }
        }
    }

    memberFilter?.addEventListener('change', applyMemberFilter);
    applyMemberFilter();

</script>
<script src="../LOGIN_PAGE/tab-auth.js?v=<?= filemtime(__DIR__ . '/../LOGIN_PAGE/tab-auth.js') ?>"></script>
</body>
</html>
