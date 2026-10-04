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
$rounds = [];
$activity = [];
$loadError = '';
$actionMessage = '';
$currentMember = null;
$currentRound = null;
$memberPayments = [];
$pendingRequests = [];
$receiptClaimed = false;
$currentMemberPaymentLocked = false;
$openPaymentDialog = false;
$paymentFormState = ['channel_id' => '', 'transaction_no' => ''];

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

function dashboardCycleDeadline(DateTimeImmutable $firstDue, string $frequency, int $roundOffset): DateTimeImmutable
{
    if ($frequency === 'Weekly') return $firstDue->modify('+' . $roundOffset . ' weeks');

    $month = $firstDue->modify('first day of this month')->modify('+' . $roundOffset . ' months');
    $day = min((int) $firstDue->format('j'), (int) $month->format('t'));
    return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)
        ->setTime((int) $firstDue->format('H'), (int) $firstDue->format('i'), (int) $firstDue->format('s'));
}

function actionButton(string $label, bool $enabled, string $action, string $token): string
{
    $disabled = $enabled ? '' : ' disabled';
    return '<form method="post" class="action-form"><input type="hidden" name="csrf_token" value="' .
        h($token) . '"><input type="hidden" name="action" value="' . h($action) .
        '"><button class="action-button" type="submit"' . $disabled . '>' . h($label) . '</button></form>';
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
                        'SELECT member_id FROM group_members WHERE group_id = :group_id
                         ORDER BY assigned_slot_number FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $groupId]);
                    $cycleMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (count($cycleMembers) === (int) $lockedGroup['total_member_slots']) {
                        $insertRound = $pdo->prepare(
                            'INSERT INTO round_schedules
                                (group_id, round_number, receiver_member_id, target_deadline, round_status)
                             VALUES (:group_id, :round_number, :receiver_member_id, :target_deadline, :round_status)'
                        );
                        $firstDue = new DateTimeImmutable($lockedGroup['first_payment_due']);
                        foreach ($cycleMembers as $roundIndex => $memberId) {
                            $insertRound->execute([
                                ':group_id' => $groupId,
                                ':round_number' => $roundIndex + 1,
                                ':receiver_member_id' => (int) $memberId,
                                ':target_deadline' => dashboardCycleDeadline($firstDue, $lockedGroup['payment_frequency'], $roundIndex)->format('Y-m-d H:i:s'),
                                ':round_status' => $roundIndex === 0 ? 'Ongoing' : 'Upcoming',
                            ]);
                        }
                    }
                }
                $pdo->commit();
            }

            $stmt = $pdo->prepare(
                'SELECT rs.round_id, rs.round_number, rs.receiver_member_id, rs.target_deadline,
                        rs.round_status, rs.actual_disbursement_date, rs.remarks,
                        receiver.username AS receiver_name,
                        COALESCE(SUM(CASE WHEN p.payment_status = "Verified" THEN p.amount_due ELSE 0 END), 0) AS collected
                 FROM round_schedules rs
                 JOIN group_members receiver_member ON receiver_member.member_id = rs.receiver_member_id
                 JOIN users receiver ON receiver.user_id = receiver_member.user_id
                 LEFT JOIN payments p ON p.round_id = rs.round_id
                 WHERE rs.group_id = :group_id
                 GROUP BY rs.round_id, rs.round_number, rs.receiver_member_id, rs.target_deadline,
                          rs.round_status, rs.actual_disbursement_date, rs.remarks, receiver.username
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

                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM audit_logs
                     WHERE group_id = :group_id AND activity_type = :activity AND details = :details'
                );
                $stmt->execute([
                    ':group_id' => $groupId,
                    ':activity' => 'Recipient Confirmed Pot',
                    ':details' => 'round_id=' . $currentRound['round_id'],
                ]);
                $receiptClaimed = (int) $stmt->fetchColumn() > 0;
            }

            $stmt = $pdo->prepare(
                'SELECT al.activity_type, al.details, al.timestamp, u.username
                 FROM audit_logs al JOIN users u ON u.user_id = al.actor_user_id
                 WHERE al.group_id = :group_id ORDER BY al.timestamp DESC LIMIT 12'
            );
            $stmt->execute([':group_id' => $groupId]);
            $activity = $stmt->fetchAll();

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

if ($group && isset($_SESSION['group_dashboard_flash'][$groupId])) {
    $actionMessage = (string) $_SESSION['group_dashboard_flash'][$groupId];
    unset($_SESSION['group_dashboard_flash'][$groupId]);
}

$hasDisbursed = $currentRound && $currentRound['actual_disbursement_date'] !== null;

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
                $stmt = $pdo->prepare('SELECT total_member_slots FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE');
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

                        if (!$alreadyMember) {
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
            || $currentRound['round_status'] !== 'Ongoing' || $hasDisbursed) {
            $actionMessage = 'Payments can only be submitted during an active, undistributed round.';
        } elseif (!$channelId || $paymentFormState['transaction_no'] === ''
            || strlen($paymentFormState['transaction_no']) > 100) {
            $actionMessage = 'Choose a payment channel and enter a transaction number (up to 100 characters).';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'SELECT group_status, contribution_amount, late_fee_rate, grace_period_hours
                     FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
                );
                $stmt->execute([':group_id' => $groupId]);
                $lockedGroup = $stmt->fetch();

                $stmt = $pdo->prepare(
                    'SELECT round_id, target_deadline, round_status, actual_disbursement_date
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
                    || $lockedRound['actual_disbursement_date'] !== null) {
                    throw new RuntimeException('The current round is no longer accepting payments.');
                }
                if ($existingPayment) {
                    throw new RuntimeException('Your payment for this round is already awaiting verification or has been verified.');
                }

                $cutoff = (new DateTimeImmutable($lockedRound['target_deadline']))
                    ->modify('+' . max(0, (int) $lockedGroup['grace_period_hours']) . ' hours');
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
                $_SESSION['group_dashboard_flash'][$groupId] = 'Payment submitted. It is awaiting ' . strtolower($nextApprover) . ' approval.';
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
                    'SELECT p.payment_id, p.payment_status, p.is_cooperator_verified,
                            p.is_operator_verified, p.transaction_no,
                            rs.round_status, rs.actual_disbursement_date, pg.group_status
                     FROM payments p
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
                } else {
                    error_log('Payment approval error: ' . $e->getMessage());
                    $actionMessage = 'The payment approval could not be saved. Please try again.';
                }
            }
        }
    } elseif ($action === 'freeze' && $canFreezeGroup && $group['group_status'] === 'Active') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE paluwagan_groups SET group_status = :status
                 WHERE group_id = :group_id AND group_status = :active'
            );
            $stmt->execute([':status' => 'Frozen', ':group_id' => $groupId, ':active' => 'Active']);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Group status changed before it could be frozen.');
            $pdo->prepare(
                'INSERT INTO audit_logs (group_id, actor_user_id, activity_type, details)
                 VALUES (:group_id, :actor, :activity, :details)'
            )->execute([':group_id' => $groupId, ':actor' => $userId, ':activity' => 'Group Frozen', ':details' => 'Group frozen by ' . $currentMember['role']]);
            $pdo->commit();
            header('Location: group_dashboard.php?id=' . $groupId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Group freeze error: ' . $e->getMessage());
            $actionMessage = 'The group could not be frozen. Please try again.';
        }
    } elseif ($action === 'disburse' && $isOperator && $group['group_status'] === 'Active' && $currentRound
        && $currentRound['round_status'] === 'Ongoing'
        && $currentRound['actual_disbursement_date'] === null) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE round_schedules SET actual_disbursement_date = NOW()
                 WHERE round_id = :round_id AND group_id = :group_id
                   AND round_status = :status AND actual_disbursement_date IS NULL'
            );
            $stmt->execute([':round_id' => $currentRound['round_id'], ':group_id' => $groupId, ':status' => 'Ongoing']);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Round status changed before disbursement.');
            $pdo->prepare(
                'INSERT INTO audit_logs (group_id, round_id, actor_user_id, activity_type, details)
                 VALUES (:group_id, :round_id, :actor, :activity, :details)'
            )->execute([
                ':group_id' => $groupId, ':round_id' => $currentRound['round_id'], ':actor' => $userId,
                ':activity' => 'Pot Disbursed', ':details' => 'round_id=' . $currentRound['round_id'],
            ]);
            $pdo->commit();
            header('Location: group_dashboard.php?id=' . $groupId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Pot disbursement error: ' . $e->getMessage());
            $actionMessage = 'The pot could not be marked as disbursed. Please try again.';
        }
    } elseif ($action === 'claim' && $isRecipient && $currentRound
        && $hasDisbursed && !$receiptClaimed) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE round_schedules SET round_status = :paid_out
                 WHERE round_id = :round_id AND group_id = :group_id
                   AND round_status = :ongoing AND actual_disbursement_date IS NOT NULL'
            );
            $stmt->execute([
                ':paid_out' => 'Paid Out', ':round_id' => $currentRound['round_id'],
                ':group_id' => $groupId, ':ongoing' => 'Ongoing',
            ]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Round status changed before receipt confirmation.');
            $stmt = $pdo->prepare(
                'SELECT round_id FROM round_schedules
                 WHERE group_id = :group_id AND round_number > :round_number
                 ORDER BY round_number LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([
                ':group_id' => $groupId, ':round_number' => (int) $currentRound['round_number'],
            ]);
            $nextRoundId = $stmt->fetchColumn();
            if ($nextRoundId && $group['group_status'] === 'Active') {
                $stmt = $pdo->prepare(
                    'UPDATE round_schedules SET round_status = :ongoing
                     WHERE round_id = :round_id AND group_id = :group_id AND round_status = :upcoming'
                );
                $stmt->execute([
                    ':ongoing' => 'Ongoing', ':round_id' => (int) $nextRoundId,
                    ':group_id' => $groupId, ':upcoming' => 'Upcoming',
                ]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('The next round could not be activated.');
            } elseif (!$nextRoundId && $group['group_status'] === 'Active') {
                $stmt = $pdo->prepare(
                    'UPDATE paluwagan_groups SET group_status = :completed
                     WHERE group_id = :group_id AND group_status = :active'
                );
                $stmt->execute([
                    ':completed' => 'Completed', ':group_id' => $groupId, ':active' => 'Active',
                ]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('The completed cycle could not be saved.');
                $pdo->prepare(
                    'INSERT INTO audit_logs (group_id, round_id, actor_user_id, activity_type, details)
                     VALUES (:group_id, :round_id, :actor, :activity, :details)'
                )->execute([
                    ':group_id' => $groupId, ':round_id' => $currentRound['round_id'], ':actor' => $userId,
                    ':activity' => 'Group Cycle Completed', ':details' => 'All scheduled rounds were paid out',
                ]);
            }
            $pdo->prepare(
                'INSERT INTO audit_logs (group_id, round_id, actor_user_id, activity_type, details)
                 VALUES (:group_id, :round_id, :actor, :activity, :details)'
            )->execute([
                ':group_id' => $groupId, ':round_id' => $currentRound['round_id'], ':actor' => $userId,
                ':activity' => 'Recipient Confirmed Pot', ':details' => 'round_id=' . $currentRound['round_id'],
            ]);
            $pdo->commit();
            header('Location: group_dashboard.php?id=' . $groupId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Payout confirmation error: ' . $e->getMessage());
            $actionMessage = 'Receipt confirmation could not be saved. Please try again.';
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
$canFreezeGroup = in_array($currentMember['role'] ?? '', ['Main Operator', 'Co-Operator'], true);
$isRecipient = $currentRound && (int) $currentRound['receiver_member_id'] === (int) ($currentMember['member_id'] ?? 0);
$hasDisbursed = $currentRound && $currentRound['actual_disbursement_date'] !== null;
$paymentCycleIsOpen = $groupStatus === 'Active' && $currentRound
    && $roundStatus === 'Ongoing' && !$hasDisbursed && count($channels) > 0;
$canSubmitPayment = $paymentCycleIsOpen && !$currentMemberPaymentLocked;
$paymentLateDays = 0;
if ($currentRound && !empty($currentRound['target_deadline'])) {
    try {
        $paymentCutoff = (new DateTimeImmutable($currentRound['target_deadline']))
            ->modify('+' . max(0, (int) $group['grace_period_hours']) . ' hours');
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
    if ($hasDisbursed && !$receiptClaimed) {
        $progressNote = 'Pending recipient confirmation';
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
if ($hasDisbursed && !$receiptClaimed) {
    $roundSubtitle = 'Main Operator Has Disbursed Pot — Awaiting ' . ($currentRound['receiver_name'] ?? 'recipient') . "'s Confirmation";
} elseif ($hasDisbursed && $receiptClaimed) {
    $roundSubtitle = 'Pot received by ' . ($currentRound['receiver_name'] ?? 'recipient');
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/svg+xml" href="../assets/toka_icon.svg">
</head>
<body>
<div class="dashboard-layout">
    <aside class="group-sidebar">
        <a class="brand" href="my_groups.php" aria-label="Toka home"><img src="../assets/Toka.svg" alt="Toka"></a>
        <nav class="side-nav" aria-label="Main navigation">
            <a href="my_groups.php">
                <span class="nav-symbol"><i class="fa-solid fa-users"></i></span> My Groups
            </a>
            <?php if ($group): ?>
                <a class="selected" href="group_dashboard.php?id=<?= (int) $groupId ?>">
                    <span class="nav-symbol"><i class="fa-solid fa-border-all"></i></span> Dashboard
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-profile">
            <span class="avatar"><?= h(strtoupper(substr($username, 0, 1))) ?></span>
            <span class="sidebar-username"><?= h($username) ?></span>
            <a class="logout-link" href="../LOGIN_PAGE/logout.php" aria-label="Log out">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </aside>

    <main class="dashboard-main">
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
                        <?php if ($hasDisbursed && !$receiptClaimed): ?>
                            <span class="round-status awaiting">Awaiting Recipient Confirmation</span>
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
                    <div class="late-fee">Late Fee Rate: <?= '₱' . h(number_format((float) $group['late_fee_rate'], 2)) ?>
                        <span class="info-tip" title="Late fee charged for an overdue contribution." aria-label="Late fee information">i</span>
                    </div>
                    <div class="top-actions">
                        <a class="outline-button" href="group_logs.php?id=<?= (int) $groupId ?>">View Logs</a>
                        <a class="outline-button" href="group_details.php?id=<?= (int) $groupId ?>">View Details</a>
                    </div>
                </div>
            </section>

            <div class="filter-row">
                <label class="visually-hidden" for="member-filter">Filter members by role</label>
                <select id="member-filter">
                    <option value="all">All</option>
                    <option value="Main Operator">Main Operator</option>
                    <option value="Co-Operator">Co-Operator</option>
                    <option value="Member">Member</option>
                </select>
            </div>

            <?php if ($actionMessage !== '' && !$openPaymentDialog): ?>
                <div class="action-notice" role="alert"><?= h($actionMessage) ?></div>
            <?php endif; ?>

            <section class="member-grid" id="members" aria-label="Group members">
                <?php foreach ($members as $member): ?>
                    <?php
                    $payment = $memberPayments[(int) $member['member_id']] ?? null;
                    $memberIsRecipient = $currentRound && (int) $currentRound['receiver_member_id'] === (int) $member['member_id'];
                    $memberPaymentState = 'Unpaid';
                    $memberPaymentTone = 'unpaid';
                    if ($payment && $payment['payment_status'] === 'Verified') {
                        $memberPaymentState = 'Verified';
                        $memberPaymentTone = 'verified';
                    } elseif ($payment && $payment['payment_status'] === 'Pending Verification'
                        && !empty($payment['is_cooperator_verified'])) {
                        $memberPaymentState = 'Pending Main Operator';
                        $memberPaymentTone = 'pending-operator';
                    } elseif ($payment && $payment['payment_status'] === 'Pending Verification') {
                        $memberPaymentState = 'Pending Co-Operator';
                        $memberPaymentTone = 'awaiting';
                    } elseif ($memberIsRecipient && $hasDisbursed && !$receiptClaimed) {
                        $memberPaymentState = 'Confirm Pot Receipt';
                        $memberPaymentTone = 'awaiting';
                    }
                    $paymentNeedsCoOperator = $payment && $payment['payment_status'] === 'Pending Verification'
                        && empty($payment['is_cooperator_verified']);
                    $paymentNeedsMainOperator = $payment && $payment['payment_status'] === 'Pending Verification'
                        && !empty($payment['is_cooperator_verified']) && empty($payment['is_operator_verified']);
                    $canReviewMemberPayment = $groupStatus === 'Active' && $currentRound
                        && $roundStatus === 'Ongoing' && !$hasDisbursed
                        && (($isCoOperator && $paymentNeedsCoOperator) || ($isOperator && $paymentNeedsMainOperator));
                    ?>
                    <article class="member-card" data-role="<?= h($member['role']) ?>">
                        <div class="member-left">
                            <span class="member-icon <?= $member['role'] === 'Main Operator' ? 'crown' : ($member['role'] === 'Co-Operator' ? 'shield' : '') ?>">
                                <?= $member['role'] === 'Main Operator' ? '♛' : ($member['role'] === 'Co-Operator' ? '⬟' : '♟') ?>
                            </span>
                            <div class="member-description">
                                <div class="member-name-line">
                                    <strong><?= h($member['username']) ?></strong>
                                    <?php if ($member['account_status'] !== 'Active'): ?><span class="account-badge"><?= h($member['account_status']) ?></span><?php endif; ?>
                                </div>
                                <span class="member-role"><?= h($member['role']) ?></span>
                                <?php if ($payment && in_array($payment['payment_status'], ['Pending Verification', 'Verified'], true)): ?>
                                    <span class="payment-detail">Paid via <?= h($payment['channel_type'] ?: 'Payment channel') ?> | Txn #: <?= h($payment['transaction_no'] ?: 'N/A') ?></span>
                                <?php elseif (!$payment && $member['role'] === 'Co-Operator'): ?>
                                    <span class="payment-detail">Payment channel details are on file</span>
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
                            <strong>Trust: <?= (int) $member['trust_score'] ?></strong>
                            <span class="slot-badge">Slot #<?= (int) $member['assigned_slot_number'] ?></span>
                            <span class="payment-state <?= h($memberPaymentTone) ?>"><?= h($memberPaymentState) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

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
                    <span class="operator-mark"><?= $isOperator ? '♛' : '♟' ?></span>
                    <div>
                        <strong>Username: <?= h($username) ?></strong>
                        <span>Role: <?= h($currentMember['role'] ?? 'Member') ?> <b>|</b> Status:
                            <span class="<?= $statusVerified ? 'text-verified' : 'text-pending' ?>"><?= $statusVerified ? 'Verified' : h($currentMember['account_status'] ?? 'Unknown') ?></span>
                        </span>
                    </div>
                </div>
                <div class="operator-actions">
                    <div class="payment-action">
                        <?php if ($canSubmitPayment): ?>
                            <button class="operator-button pay-button" type="button" data-open-payment>Submit payment</button>
                        <?php elseif ($currentMemberPaymentLocked): ?>
                            <button class="operator-button muted-button" type="button" disabled aria-describedby="payment-submitted-message">Submit payment</button>
                            <span class="payment-submitted-message" id="payment-submitted-message" role="status">You already submitted your payment for this cycle.</span>
                        <?php else: ?>
                            <button class="operator-button muted-button" type="button" disabled>Submit payment</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($isOperator): ?>
                        <?php if ($groupStatus === 'Active' && $currentRound && $roundStatus === 'Ongoing' && !$hasDisbursed): ?>
                            <?= actionButton('Disburse pot', true, 'disburse', $csrfToken) ?>
                        <?php else: ?>
                            <button class="operator-button muted-button" type="button" disabled>Disburse pot</button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($canFreezeGroup && $groupStatus === 'Active'): ?>
                        <?= actionButton('Freeze Group', true, 'freeze', $csrfToken) ?>
                    <?php endif; ?>
                    <?php if ($isRecipient && $currentRound && $hasDisbursed && !$receiptClaimed): ?>
                        <?= actionButton('Claim Pot', true, 'claim', $csrfToken) ?>
                    <?php else: ?>
                        <button class="operator-button muted-button" type="button" disabled>Claim Pot</button>
                    <?php endif; ?>
                </div>
            </section>

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
                                <input type="text" value="<?= h($paymentStatusLabel) ?>" readonly>
                            </label>
                            <label>Amount to Pay
                                <input type="text" value="₱<?= h(number_format($paymentTotal, 2)) ?>" readonly>
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
                            <input type="text" name="transaction_no" maxlength="100" required
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


        <?php endif; ?>
    </main>
</div>
<script>
    const requestsDialog = document.getElementById('join-requests-dialog');
    if (requestsDialog) requestsDialog.showModal();
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


    const memberFilter = document.getElementById('member-filter');
    memberFilter?.addEventListener('change', () => {
        document.querySelectorAll('.member-card').forEach(card => {
            card.hidden = memberFilter.value !== 'all' && card.dataset.role !== memberFilter.value;
        });
    });

    document.querySelectorAll('.action-form').forEach(form => {
        form.addEventListener('submit', event => {
            const action = form.querySelector('[name="action"]').value;
            const confirmText = action === 'freeze'
                ? 'Freeze this group? Members will no longer be able to continue the current round.'
                : action === 'disburse'
                    ? 'Confirm that the pot for this round has been disbursed?'
                    : 'Confirm that you have received the group pot?';
            if (!window.confirm(confirmText)) event.preventDefault();
        });
    });
</script>
</body>
</html>

