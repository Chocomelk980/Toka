<?php

function tokaFreezePollsForUser(PDO $pdo, int $userId, ?int $groupId = null): array
{
    $sql = 'SELECT fp.poll_id, fp.group_id, fp.initiator_user_id, fp.reason,
                   fp.poll_status, fp.created_at, pg.group_name,
                   initiator.username AS initiator_name,
                   COALESCE(electorate.eligible_count, 0) AS eligible_count,
                   COALESCE(vote_totals.approvals, 0) AS approvals,
                   COALESCE(vote_totals.rejections, 0) AS rejections,
                   own_vote.vote_choice AS my_vote, fpm.outcome_seen_at
            FROM freeze_poll_members fpm
            JOIN freeze_polls fp ON fp.poll_id = fpm.poll_id
            JOIN paluwagan_groups pg ON pg.group_id = fp.group_id
            JOIN users initiator ON initiator.user_id = fp.initiator_user_id
            LEFT JOIN (
                SELECT poll_id, COUNT(*) AS eligible_count
                FROM freeze_poll_members GROUP BY poll_id
            ) electorate ON electorate.poll_id = fp.poll_id
            LEFT JOIN (
                SELECT poll_id,
                       SUM(vote_choice = \'Yes\') AS approvals,
                       SUM(vote_choice = \'No\') AS rejections
                FROM poll_votes GROUP BY poll_id
            ) vote_totals ON vote_totals.poll_id = fp.poll_id
            LEFT JOIN poll_votes own_vote
              ON own_vote.poll_id = fp.poll_id AND own_vote.user_id = :vote_user_id
            WHERE fpm.user_id = :member_user_id';
    $params = [':vote_user_id' => $userId, ':member_user_id' => $userId];
    if ($groupId !== null) {
        $sql .= ' AND fp.group_id = :group_id';
        $params[':group_id'] = $groupId;
    }
    // Show the newest final result first so an old approval cannot precede a newer rejection.
    $sql .= ' ORDER BY fp.created_at DESC, fp.poll_id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function tokaFreezePollFingerprint(array $polls): string
{
    $state = [];
    foreach ($polls as $poll) {
        $state[] = [
            (int) $poll['poll_id'],
            (string) $poll['poll_status'],
            (int) $poll['approvals'],
            (int) $poll['rejections'],
            $poll['my_vote'],
            $poll['outcome_seen_at'],
        ];
    }
    return hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES) ?: '[]');
}
