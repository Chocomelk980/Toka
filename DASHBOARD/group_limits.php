<?php

class TokaGroupLimitException extends RuntimeException
{
}

// Serialize membership additions for one user across different groups/sessions.
// Call before the transaction's first ordinary SELECT so the limit uses fresh data.
function tokaLockGroupLimitUser(PDO $pdo, int $userId): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Group admission requires a transaction.');
    }
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = :user_id FOR UPDATE');
    $stmt->execute([':user_id' => $userId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('The group applicant could not be found.');
    }
}

function tokaGroupLimitError(PDO $pdo, int $userId, bool $forOperator = false): ?string
{
    // All roles count once per group. Pending requests are not memberships;
    // eligibility must be checked again when the operator approves a request.
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT CASE WHEN pg.group_status IN ("Waiting", "Active")
                                   THEN pg.group_id END) AS current_groups,
                COUNT(DISTINCT CASE WHEN pg.group_status = "Completed"
                                   THEN pg.group_id END) AS completed_groups
         FROM group_members gm
         JOIN paluwagan_groups pg ON pg.group_id = gm.group_id
         WHERE gm.user_id = :user_id'
    );
    $stmt->execute([':user_id' => $userId]);
    $counts = $stmt->fetch();
    $hasCompletedGroup = (int) $counts['completed_groups'] > 0;
    $limit = $hasCompletedGroup ? 2 : 1;
    if ((int) $counts['current_groups'] < $limit) {
        return null;
    }

    if (!$hasCompletedGroup) {
        return $forOperator
            ? 'This user can only have 1 waiting or active group until they complete their first group. They must complete that group before joining another.'
            : 'You can only have 1 waiting or active group until you complete your first group. Complete that group to unlock the 2-group limit.';
    }
    return $forOperator
        ? 'This user already has 2 or more groups across waiting and active groups. They must have fewer than 2 before joining another.'
        : 'You can only have 2 groups in total across waiting and active groups. Complete a group before creating or joining another.';
}
