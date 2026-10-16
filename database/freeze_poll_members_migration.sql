CREATE TABLE IF NOT EXISTS freeze_poll_members (
    poll_id INT NOT NULL,
    user_id INT NOT NULL,
    outcome_seen_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (poll_id, user_id),
    KEY idx_freeze_poll_members_user_outcome (user_id, outcome_seen_at),
    CONSTRAINT fk_freeze_poll_members_poll
        FOREIGN KEY (poll_id) REFERENCES freeze_polls(poll_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_freeze_poll_members_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO freeze_poll_members (poll_id, user_id)
SELECT fp.poll_id, gm.user_id
FROM freeze_polls fp
JOIN paluwagan_groups pg ON pg.group_id = fp.group_id
JOIN group_members gm ON gm.group_id = fp.group_id
WHERE fp.poll_status = 'Active'
  AND pg.group_status = 'Active';
