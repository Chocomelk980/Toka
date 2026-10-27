<?php

function tokaEnsureAdminArchiveTables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_log_archives (
            log_id INT NOT NULL,
            archived_by INT NOT NULL,
            archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (log_id),
            KEY idx_audit_log_archives_archived_by (archived_by),
            KEY idx_audit_log_archives_archived_at (archived_at),
            CONSTRAINT fk_audit_log_archives_log
                FOREIGN KEY (log_id) REFERENCES audit_logs(log_id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_audit_log_archives_admin
                FOREIGN KEY (archived_by) REFERENCES users(user_id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_archives (
            group_id INT NOT NULL,
            archived_by INT NOT NULL,
            archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id),
            KEY idx_group_archives_archived_by (archived_by),
            KEY idx_group_archives_archived_at (archived_at),
            CONSTRAINT fk_group_archives_group
                FOREIGN KEY (group_id) REFERENCES paluwagan_groups(group_id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_group_archives_admin
                FOREIGN KEY (archived_by) REFERENCES users(user_id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS round_archives (
            round_id INT NOT NULL,
            archived_by INT NOT NULL,
            archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (round_id),
            KEY idx_round_archives_archived_by (archived_by),
            KEY idx_round_archives_archived_at (archived_at),
            CONSTRAINT fk_round_archives_round
                FOREIGN KEY (round_id) REFERENCES round_schedules(round_id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_round_archives_admin
                FOREIGN KEY (archived_by) REFERENCES users(user_id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_pot_archives (
            group_id INT NOT NULL,
            archived_by INT NOT NULL,
            archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            group_status VARCHAR(32) NOT NULL,
            round_number INT NULL,
            round_status VARCHAR(32) NULL,
            due_at DATETIME NULL,
            accumulated_pot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            expected_pot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_accumulation DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (group_id),
            KEY idx_group_pot_archives_archived_by (archived_by),
            KEY idx_group_pot_archives_archived_at (archived_at),
            CONSTRAINT fk_group_pot_archives_group
                FOREIGN KEY (group_id) REFERENCES paluwagan_groups(group_id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_group_pot_archives_admin
                FOREIGN KEY (archived_by) REFERENCES users(user_id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}
