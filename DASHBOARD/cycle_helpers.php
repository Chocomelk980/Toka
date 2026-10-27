<?php

function tokaCycleFrequencyOptions(): array
{
    return [
        'Weekly' => 'Weekly',
        'Biweekly' => 'Biweekly',
        'Monthly' => 'Monthly',
        'Bimonthly' => 'Bimonthly',
        'Quarterly' => 'Quarterly',
    ];
}

function tokaCycleGraceHours(): int
{
    return 72;
}

function tokaCycleGraceDeadline(DateTimeImmutable $targetDeadline): DateTimeImmutable
{
    return $targetDeadline->modify('+' . tokaCycleGraceHours() . ' hours');
}

function tokaCycleFirstDueAfterWaiting(
    string $createdAt,
    string $selectedFirstDue,
    ?DateTimeImmutable $startedAt = null
): DateTimeImmutable {
    $created = new DateTimeImmutable($createdAt);
    $selectedDue = new DateTimeImmutable($selectedFirstDue);
    $start = $startedAt ?? new DateTimeImmutable('now');
    $calendarDays = (int) $created->setTime(0, 0)->diff($selectedDue->setTime(0, 0))->format('%r%a');

    if ($calendarDays <= 0) {
        $leadSeconds = max(0, $selectedDue->getTimestamp() - $created->getTimestamp());
        return $start->modify('+' . $leadSeconds . ' seconds');
    }

    return $start->modify('+' . $calendarDays . ' days')->setTime(
        (int) $selectedDue->format('H'),
        (int) $selectedDue->format('i'),
        (int) $selectedDue->format('s')
    );
}

function tokaCycleGraceExpired(string $targetDeadline, ?DateTimeImmutable $now = null): bool
{
    return ($now ?? new DateTimeImmutable('now')) >= tokaCycleGraceDeadline(new DateTimeImmutable($targetDeadline));
}

function tokaPaymentTrustAdjustment(DateTimeImmutable $targetDeadline, DateTimeImmutable $submittedAt): int
{
    $secondsLate = $submittedAt->getTimestamp() - $targetDeadline->getTimestamp();
    if ($secondsLate <= 0) return 5;

    $lateDays = (int) ceil($secondsLate / 86400);
    return $lateDays <= 2 ? -10 : -25;
}

function tokaAdjustUserTrustScore(PDO $pdo, int $userId, int $adjustment): int
{
    $pdo->prepare(
        'UPDATE users
         SET trust_score = LEAST(100, GREATEST(0, trust_score + :adjustment))
         WHERE user_id = :user_id'
    )->execute([':adjustment' => $adjustment, ':user_id' => $userId]);

    $stmt = $pdo->prepare('SELECT trust_score, account_status FROM users WHERE user_id = :user_id FOR UPDATE');
    $stmt->execute([':user_id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) throw new RuntimeException('The user trust score could not be updated.');

    $trustScore = (int) $user['trust_score'];
    if ($trustScore === 0 && $user['account_status'] !== 'Banned') {
        $pdo->prepare(
            'UPDATE users SET account_status = :status, ban_reason = :reason WHERE user_id = :user_id'
        )->execute([
            ':status' => 'Banned', ':reason' => 'Trust score too low.', ':user_id' => $userId,
        ]);
    }

    return $trustScore;
}

function tokaApplyMissedCyclePayments(PDO $pdo, int $groupId, int $roundId, int $actorUserId): void
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT group_status, contribution_amount
             FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
        );
        $stmt->execute([':group_id' => $groupId]);
        $group = $stmt->fetch();

        $stmt = $pdo->prepare(
            'SELECT target_deadline, round_status, recipient_claimed_at
             FROM round_schedules WHERE round_id = :round_id AND group_id = :group_id FOR UPDATE'
        );
        $stmt->execute([':round_id' => $roundId, ':group_id' => $groupId]);
        $round = $stmt->fetch();

        if (!$group || $group['group_status'] !== 'Active' || !$round
            || $round['round_status'] !== 'Ongoing' || $round['recipient_claimed_at'] !== null
            || !tokaCycleGraceExpired($round['target_deadline'])) {
            if ($ownsTransaction) $pdo->commit();
            return;
        }

        $stmt = $pdo->prepare(
            'SELECT gm.member_id, gm.user_id, u.username
             FROM group_members gm
             JOIN users u ON u.user_id = gm.user_id
             WHERE gm.group_id = :group_id
               AND NOT EXISTS (
                   SELECT 1 FROM payments p
                   WHERE p.round_id = :round_id_for_payment AND p.member_id = gm.member_id
                     AND p.payment_status IN (:pending, :verified)
               )
               AND NOT EXISTS (
                   SELECT 1 FROM payments missed
                   WHERE missed.round_id = :round_id_for_marker AND missed.member_id = gm.member_id
                     AND missed.payment_status = :overdue
                     AND missed.channel_id IS NULL AND missed.transaction_no IS NULL
                     AND missed.is_member_confirmed = 0
               )
             FOR UPDATE'
        );
        $stmt->execute([
            ':group_id' => $groupId,
            ':round_id_for_payment' => $roundId,
            ':round_id_for_marker' => $roundId,
            ':pending' => 'Pending Verification', ':verified' => 'Verified',
            ':overdue' => 'Overdue',
        ]);
        $missedMembers = $stmt->fetchAll();

        $insertMissed = $pdo->prepare(
            'INSERT INTO payments
                (round_id, member_id, channel_id, amount_due, late_fee_applied, transaction_no,
                 is_member_confirmed, is_cooperator_verified, is_operator_verified, payment_status)
             VALUES (:round_id, :member_id, NULL, :amount_due, 0, NULL, 0, 0, 0, :overdue)'
        );
        $logMissed = $pdo->prepare(
            'INSERT INTO audit_logs
                (group_id, round_id, payment_id, actor_user_id, activity_type, details, verification_status)
             VALUES (:group_id, :round_id, :payment_id, :actor, :activity, :details, :verification)'
        );
        foreach ($missedMembers as $missedMember) {
            $insertMissed->execute([
                ':round_id' => $roundId,
                ':member_id' => (int) $missedMember['member_id'],
                ':amount_due' => number_format((float) $group['contribution_amount'], 2, '.', ''),
                ':overdue' => 'Overdue',
            ]);
            $missedPaymentId = (int) $pdo->lastInsertId();
            tokaAdjustUserTrustScore($pdo, (int) $missedMember['user_id'], -50);
            $logMissed->execute([
                ':group_id' => $groupId,
                ':round_id' => $roundId,
                ':payment_id' => $missedPaymentId,
                ':actor' => $actorUserId,
                ':activity' => 'Payment Marked Missed',
                ':details' => $missedMember['username'] . ' did not submit payment within the 3-day grace period; trust score -50 points.',
                ':verification' => 'Grace Period Expired',
            ]);
        }

        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tokaCycleRoundDeadline(DateTimeImmutable $firstDue, string $frequency, int $roundOffset): DateTimeImmutable
{
    $weeksPerRound = match ($frequency) {
        'Weekly' => 1,
        'Biweekly' => 2,
        default => 0,
    };
    if ($weeksPerRound > 0) return $firstDue->modify('+' . ($roundOffset * $weeksPerRound) . ' weeks');

    $monthsPerRound = match ($frequency) {
        'Bimonthly' => 2,
        'Quarterly' => 3,
        'Semiannual' => 6,
        default => 1,
    };
    $month = $firstDue->modify('first day of this month')->modify('+' . ($roundOffset * $monthsPerRound) . ' months');
    $day = min((int) $firstDue->format('j'), (int) $month->format('t'));
    return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)
        ->setTime((int) $firstDue->format('H'), (int) $firstDue->format('i'), (int) $firstDue->format('s'));
}

function tokaCycleReceiverOrder(array $members): array
{
    $operatorIds = [];
    $coOperatorIds = [];
    $memberIdsByTrust = [];

    foreach ($members as $member) {
        $memberId = (int) $member['member_id'];
        if ($member['role'] === 'Main Operator') {
            $operatorIds[] = $memberId;
        } elseif ($member['role'] === 'Co-Operator') {
            $coOperatorIds[] = $memberId;
        } else {
            $trustScore = (int) ($member['trust_score'] ?? 100);
            $memberIdsByTrust[$trustScore][] = $memberId;
        }
    }

    if (count($operatorIds) !== 1 || count($coOperatorIds) > 1) {
        throw new RuntimeException('A cycle requires one Main Operator and at most one Co-Operator.');
    }

    krsort($memberIdsByTrust, SORT_NUMERIC);
    $memberIds = [];
    foreach ($memberIdsByTrust as $tiedMemberIds) {
        shuffle($tiedMemberIds);
        array_push($memberIds, ...$tiedMemberIds);
    }

    return array_merge($memberIds, $coOperatorIds, $operatorIds);
}

function tokaCreateCycleRounds(
    PDO $pdo,
    int $groupId,
    array $members,
    DateTimeImmutable $firstDue,
    string $frequency
): void {
    $receiverOrder = tokaCycleReceiverOrder($members);
    $insertRound = $pdo->prepare(
        'INSERT INTO round_schedules
            (group_id, round_number, receiver_member_id, target_deadline, round_status)
         VALUES (:group_id, :round_number, :receiver_member_id, :target_deadline, :round_status)'
    );

    foreach ($receiverOrder as $roundIndex => $memberId) {
        $insertRound->execute([
            ':group_id' => $groupId,
            ':round_number' => $roundIndex + 1,
            ':receiver_member_id' => $memberId,
            ':target_deadline' => tokaCycleRoundDeadline($firstDue, $frequency, $roundIndex)->format('Y-m-d H:i:s'),
            ':round_status' => $roundIndex === 0 ? 'Ongoing' : 'Upcoming',
        ]);
    }
}

function tokaClaimCyclePot(PDO $pdo, int $groupId, int $roundId, int $userId, int $receiverMemberId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT group_status, total_member_slots, contribution_amount
             FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
        );
        $stmt->execute([':group_id' => $groupId]);
        $group = $stmt->fetch();

        $stmt = $pdo->prepare(
            'SELECT rs.receiver_member_id, rs.target_deadline, rs.round_status,
                    rs.recipient_claimed_at, rs.operator_approved_at
             FROM round_schedules rs
             JOIN group_members gm ON gm.member_id = rs.receiver_member_id
             WHERE rs.round_id = :round_id AND rs.group_id = :group_id AND gm.user_id = :user_id
             FOR UPDATE'
        );
        $stmt->execute([':round_id' => $roundId, ':group_id' => $groupId, ':user_id' => $userId]);
        $round = $stmt->fetch();

        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT p.member_id)
             FROM payments p
             JOIN group_members gm ON gm.member_id = p.member_id AND gm.group_id = :group_id
             WHERE p.round_id = :round_id AND p.payment_status = :verified'
        );
        $stmt->execute([':group_id' => $groupId, ':round_id' => $roundId, ':verified' => 'Verified']);
        $verifiedMemberCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = :group_id');
        $stmt->execute([':group_id' => $groupId]);
        $memberCount = (int) $stmt->fetchColumn();

        $graceDeadline = tokaCycleGraceDeadline(new DateTimeImmutable($round['target_deadline'] ?? 'now'));
        $graceExpired = new DateTimeImmutable('now') >= $graceDeadline;

        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT p.member_id)
             FROM payments p
             JOIN group_members gm ON gm.member_id = p.member_id AND gm.group_id = :group_id
             WHERE p.round_id = :round_id AND p.payment_status = :pending'
        );
        $stmt->execute([
            ':group_id' => $groupId, ':round_id' => $roundId,
            ':pending' => 'Pending Verification',
        ]);
        $pendingMemberCount = (int) $stmt->fetchColumn();

        if (!$group || $group['group_status'] !== 'Active' || !$round
            || (int) $round['receiver_member_id'] !== $receiverMemberId
            || $round['round_status'] !== 'Ongoing'
            || $round['recipient_claimed_at'] !== null
            || $round['operator_approved_at'] !== null
            || $memberCount !== (int) $group['total_member_slots']
            || ($verifiedMemberCount !== $memberCount && (!$graceExpired || $pendingMemberCount > 0))) {
            throw new DomainException('The pot is not ready to claim for this receiver yet.');
        }

        if ($graceExpired) tokaApplyMissedCyclePayments($pdo, $groupId, $roundId, $userId);

        $stmt = $pdo->prepare(
            'UPDATE round_schedules SET recipient_claimed_at = NOW()
             WHERE round_id = :round_id AND group_id = :group_id
               AND round_status = :ongoing AND recipient_claimed_at IS NULL AND operator_approved_at IS NULL'
        );
        $stmt->execute([':round_id' => $roundId, ':group_id' => $groupId, ':ongoing' => 'Ongoing']);
        if ($stmt->rowCount() !== 1) throw new DomainException('This pot claim was already submitted.');

        $pdo->prepare(
            'INSERT INTO audit_logs (group_id, round_id, actor_user_id, activity_type, details)
             VALUES (:group_id, :round_id, :actor, :activity, :details)'
        )->execute([
            ':group_id' => $groupId, ':round_id' => $roundId, ':actor' => $userId,
            ':activity' => 'Recipient Confirmed Pot',
            ':details' => 'round_id=' . $roundId . '; awaiting Main Operator approval',
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tokaApproveCyclePotClaim(PDO $pdo, int $groupId, int $roundId, int $userId): bool
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT group_status FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
        );
        $stmt->execute([':group_id' => $groupId]);
        $group = $stmt->fetch();

        $stmt = $pdo->prepare(
            'SELECT rs.round_number, rs.round_status, rs.recipient_claimed_at,
                    rs.operator_approved_at, receiver.username AS receiver_name,
                    receiver_member.role AS receiver_role
             FROM round_schedules rs
             JOIN group_members receiver_member ON receiver_member.member_id = rs.receiver_member_id
             JOIN users receiver ON receiver.user_id = receiver_member.user_id
             WHERE rs.round_id = :round_id AND rs.group_id = :group_id FOR UPDATE'
        );
        $stmt->execute([':round_id' => $roundId, ':group_id' => $groupId]);
        $round = $stmt->fetch();

        $approverRole = ($round['receiver_role'] ?? '') === 'Main Operator'
            ? 'Co-Operator'
            : 'Main Operator';

        $stmt = $pdo->prepare(
            'SELECT member_id FROM group_members
             WHERE group_id = :group_id AND user_id = :user_id AND role = :role
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':group_id' => $groupId, ':user_id' => $userId, ':role' => $approverRole]);
        $approverMemberId = $stmt->fetchColumn();

        if (!$group || $group['group_status'] !== 'Active' || !$round || !$approverMemberId
            || $round['round_status'] !== 'Ongoing' || $round['recipient_claimed_at'] === null
            || $round['operator_approved_at'] !== null) {
            throw new DomainException('There is no pot claim waiting for your approval.');
        }

        $stmt = $pdo->prepare(
            'UPDATE round_schedules
             SET operator_approved_at = NOW(), actual_disbursement_date = COALESCE(actual_disbursement_date, NOW()),
                 round_status = :paid_out
             WHERE round_id = :round_id AND group_id = :group_id AND round_status = :ongoing
               AND recipient_claimed_at IS NOT NULL AND operator_approved_at IS NULL'
        );
        $stmt->execute([
            ':paid_out' => 'Paid Out', ':round_id' => $roundId,
            ':group_id' => $groupId, ':ongoing' => 'Ongoing',
        ]);
        if ($stmt->rowCount() !== 1) throw new DomainException('This pot claim was already approved.');

        $stmt = $pdo->prepare(
            'SELECT round_id FROM round_schedules
             WHERE group_id = :group_id AND round_number > :round_number AND round_status = :upcoming
             ORDER BY round_number LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([
            ':group_id' => $groupId, ':round_number' => (int) $round['round_number'],
            ':upcoming' => 'Upcoming',
        ]);
        $nextRoundId = $stmt->fetchColumn();

        if ($nextRoundId) {
            $stmt = $pdo->prepare(
                'UPDATE round_schedules SET round_status = :ongoing
                 WHERE round_id = :round_id AND group_id = :group_id AND round_status = :upcoming'
            );
            $stmt->execute([
                ':ongoing' => 'Ongoing', ':round_id' => (int) $nextRoundId,
                ':group_id' => $groupId, ':upcoming' => 'Upcoming',
            ]);
            if ($stmt->rowCount() !== 1) throw new DomainException('The next round could not be activated.');
        } else {
            $stmt = $pdo->prepare(
                'UPDATE paluwagan_groups SET group_status = :completed
                 WHERE group_id = :group_id AND group_status = :active'
            );
            $stmt->execute([':completed' => 'Completed', ':group_id' => $groupId, ':active' => 'Active']);
            if ($stmt->rowCount() !== 1) throw new DomainException('The completed cycle could not be saved.');
        }

        $pdo->prepare(
            'INSERT INTO audit_logs (group_id, round_id, actor_user_id, activity_type, details, verification_status)
             VALUES (:group_id, :round_id, :actor, :activity, :details, :verification)'
        )->execute([
            ':group_id' => $groupId, ':round_id' => $roundId, ':actor' => $userId,
            ':activity' => 'Pot Claim Approved',
            ':details' => 'The ' . $approverRole . ' approved ' . $round['receiver_name'] . "'s claim; the pot was marked as disbursed.",
            ':verification' => $approverRole,
        ]);

        if (!$nextRoundId) {
            $pdo->prepare(
                'INSERT INTO audit_logs (group_id, round_id, actor_user_id, activity_type, details)
                 VALUES (:group_id, :round_id, :actor, :activity, :details)'
            )->execute([
                ':group_id' => $groupId, ':round_id' => $roundId, ':actor' => $userId,
                ':activity' => 'Group Cycle Completed', ':details' => 'All scheduled rounds were approved and paid out',
            ]);
        }

        $pdo->commit();
        return !$nextRoundId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
