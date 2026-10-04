CREATE DATABASE IF NOT EXISTS toka_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE toka_db;

-- Entity-to-table mapping for the database design:
-- USER -> users
-- PALUWAGAN_GROUP -> paluwagan_groups
-- GROUP_PAYMENT_CHANNEL -> group_payment_channels
-- GROUP_MEMBER -> group_members
-- ROUND_SCHEDULE -> round_schedules
-- PAYMENT -> payments
-- FREEZE_POLL -> freeze_polls
-- POLL_VOTE -> poll_votes
-- AUDIT_LOG -> audit_logs

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS poll_votes;
DROP TABLE IF EXISTS freeze_polls;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS round_schedules;
DROP TABLE IF EXISTS join_requests;
DROP TABLE IF EXISTS group_members;
DROP TABLE IF EXISTS group_payment_channels;
DROP TABLE IF EXISTS paluwagan_groups;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    user_id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    trust_score INT NOT NULL DEFAULT 100,
    is_admin BOOLEAN NOT NULL DEFAULT FALSE,
    account_status ENUM('Active', 'Suspended', 'Banned', 'Action Needed') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_account_status (account_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE paluwagan_groups (
    group_id INT NOT NULL AUTO_INCREMENT,
    group_code VARCHAR(20) NOT NULL,
    group_name VARCHAR(100) NOT NULL,
    description_rules TEXT NULL,
    contribution_amount DECIMAL(10,2) NOT NULL,
    total_member_slots INT NOT NULL,
    late_fee_rate DECIMAL(10,2) NOT NULL,
    payment_frequency ENUM('Weekly', 'Monthly') NOT NULL,
    grace_period_hours INT NOT NULL,
    calculated_total_pot DECIMAL(10,2) NOT NULL,
    first_payment_due DATETIME NOT NULL,
    group_status ENUM('Waiting', 'Active', 'Frozen', 'Dissolved', 'Completed') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id),
    UNIQUE KEY uq_paluwagan_groups_code (group_code),
    INDEX idx_group_status (group_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_payment_channels (
    channel_id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    channel_type ENUM('GCash', 'Maya', 'Bank Transfer', 'Cash', 'Other') NULL,
    account_name VARCHAR(100) NULL,
    account_number VARCHAR(50) NULL,
    PRIMARY KEY (channel_id),
    KEY idx_group_payment_channels_group_id (group_id),
    CONSTRAINT fk_group_payment_channels_group
        FOREIGN KEY (group_id) REFERENCES paluwagan_groups(group_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_members (
    member_id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('Main Operator', 'Co-Operator', 'Member') NOT NULL,
    assigned_slot_number INT NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    UNIQUE KEY uq_group_member (group_id, user_id),
    KEY idx_group_members_group_id (group_id),
    KEY idx_group_members_user_id (user_id),
    CONSTRAINT fk_group_members_group
        FOREIGN KEY (group_id) REFERENCES paluwagan_groups(group_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_group_members_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE join_requests (
    request_id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    request_status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL DEFAULT NULL,
    responded_by INT NULL,
    PRIMARY KEY (request_id),
    KEY idx_join_requests_group_status_time (group_id, request_status, requested_at),
    KEY idx_join_requests_user_group (user_id, group_id),
    CONSTRAINT fk_join_requests_group
        FOREIGN KEY (group_id) REFERENCES paluwagan_groups(group_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_join_requests_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_join_requests_responder
        FOREIGN KEY (responded_by) REFERENCES users(user_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE round_schedules (
    round_id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    round_number INT NOT NULL,
    receiver_member_id INT NOT NULL,
    target_deadline DATETIME NOT NULL,
    round_status ENUM('Upcoming', 'Ongoing', 'Paid Out') NOT NULL,
    actual_disbursement_date DATETIME NULL,
    remarks VARCHAR(255) NULL,
    PRIMARY KEY (round_id),
    KEY idx_round_schedules_group_id (group_id),
    KEY idx_round_schedules_receiver_member_id (receiver_member_id),
    CONSTRAINT fk_round_schedules_group
        FOREIGN KEY (group_id) REFERENCES paluwagan_groups(group_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_round_schedules_member
        FOREIGN KEY (receiver_member_id) REFERENCES group_members(member_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    payment_id INT NOT NULL AUTO_INCREMENT,
    round_id INT NOT NULL,
    member_id INT NOT NULL,
    channel_id INT NULL,
    amount_due DECIMAL(10,2) NOT NULL,
    late_fee_applied DECIMAL(10,2) NOT NULL,
    transaction_no VARCHAR(100) NULL,
    is_member_confirmed BOOLEAN NOT NULL,
    is_operator_verified BOOLEAN NOT NULL,
    payment_status ENUM('Unpaid', 'Overdue', 'Pending Verification', 'Verified', 'Rejected') NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    KEY idx_payments_round_id (round_id),
    KEY idx_payments_member_id (member_id),
    KEY idx_payments_channel_id (channel_id),
    CONSTRAINT fk_payments_round
        FOREIGN KEY (round_id) REFERENCES round_schedules(round_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_payments_member
        FOREIGN KEY (member_id) REFERENCES group_members(member_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_payments_channel
        FOREIGN KEY (channel_id) REFERENCES group_payment_channels(channel_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE freeze_polls (
    poll_id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    initiator_user_id INT NOT NULL,
    reason VARCHAR(150) NOT NULL,
    poll_status ENUM('Active', 'Passed', 'Failed') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (poll_id),
    KEY idx_freeze_polls_group_id (group_id),
    KEY idx_freeze_polls_initiator_user_id (initiator_user_id),
    CONSTRAINT fk_freeze_polls_group
        FOREIGN KEY (group_id) REFERENCES paluwagan_groups(group_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_freeze_polls_initiator
        FOREIGN KEY (initiator_user_id) REFERENCES users(user_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE poll_votes (
    vote_id INT NOT NULL AUTO_INCREMENT,
    poll_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_choice ENUM('Yes', 'No') NOT NULL,
    voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (vote_id),
    UNIQUE KEY uq_poll_votes_user_poll (poll_id, user_id),
    KEY idx_poll_votes_poll_id (poll_id),
    KEY idx_poll_votes_user_id (user_id),
    CONSTRAINT fk_poll_votes_poll
        FOREIGN KEY (poll_id) REFERENCES freeze_polls(poll_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_poll_votes_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    log_id INT NOT NULL AUTO_INCREMENT,
    group_id INT NULL,
    round_id INT NULL,
    payment_id INT NULL,
    actor_user_id INT NOT NULL,
    activity_type VARCHAR(100) NOT NULL,
    details TEXT NULL,
    verification_status VARCHAR(50) NULL,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_audit_logs_group_id (group_id),
    KEY idx_audit_logs_round_id (round_id),
    KEY idx_audit_logs_payment_id (payment_id),
    KEY idx_audit_logs_actor_user_id (actor_user_id),
    CONSTRAINT fk_audit_logs_group
        FOREIGN KEY (group_id) REFERENCES paluwagan_groups(group_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_audit_logs_round
        FOREIGN KEY (round_id) REFERENCES round_schedules(round_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_audit_logs_payment
        FOREIGN KEY (payment_id) REFERENCES payments(payment_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_audit_logs_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

