-- Canonical database setup for TOKAV2. Import this file only; no separate
-- migration scripts are required.
-- Includes cycle claim approval, user full names and ban reasons, expanded group options,
-- and freeze poll membership/outcome tracking.
-- This is a fresh-install/reset script: importing it replaces the tables
-- and their data with the schema and seed data below.

CREATE DATABASE IF NOT EXISTS toka_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE toka_db;

-- Entity-to-table mapping for the database design:
-- USER -> users
-- PALUWAGAN_GROUP -> paluwagan_groups
-- GROUP_PAYMENT_CHANNEL -> group_payment_channels
-- GROUP_MEMBER -> group_members
-- JOIN_REQUEST -> join_requests
-- ROUND_SCHEDULE -> round_schedules
-- PAYMENT -> payments
-- FREEZE_POLL -> freeze_polls
-- POLL_VOTE -> poll_votes
-- FREEZE_POLL_MEMBER -> freeze_poll_members
-- AUDIT_LOG -> audit_logs

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS freeze_poll_members;
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
    full_name VARCHAR(150) NULL,
    email VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    trust_score INT NOT NULL DEFAULT 100,
    is_admin BOOLEAN NOT NULL DEFAULT FALSE,
    account_status ENUM('Active', 'Suspended', 'Banned', 'Action Needed') NOT NULL DEFAULT 'Active',
    ban_reason VARCHAR(500) NULL,
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
    payment_frequency ENUM('Weekly', 'Biweekly', 'Monthly', 'Bimonthly', 'Quarterly', 'Semiannual') NOT NULL,
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
    channel_type ENUM('GCash', 'Maya', 'Bank Transfer', 'Cash', 'Cash on hand', 'Other') NULL,
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
    recipient_claimed_at DATETIME NULL,
    operator_approved_at DATETIME NULL,
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
    is_cooperator_verified BOOLEAN NOT NULL DEFAULT 0,
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

CREATE TABLE freeze_poll_members (
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

CREATE TABLE audit_log_archives (
    log_id INT NOT NULL,
    archived_by INT NOT NULL,
    archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY idx_audit_log_archives_archived_by (archived_by),
    KEY idx_audit_log_archives_archived_at (archived_at),
    CONSTRAINT fk_audit_log_archives_log
        FOREIGN KEY (log_id) REFERENCES audit_logs(log_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_audit_log_archives_admin
        FOREIGN KEY (archived_by) REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_archives (
    group_id INT NOT NULL,
    archived_by INT NOT NULL,
    archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id),
    KEY idx_group_archives_archived_by (archived_by),
    KEY idx_group_archives_archived_at (archived_at),
    CONSTRAINT fk_group_archives_group
        FOREIGN KEY (group_id) REFERENCES paluwagan_groups(group_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_group_archives_admin
        FOREIGN KEY (archived_by) REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE round_archives (
    round_id INT NOT NULL,
    archived_by INT NOT NULL,
    archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (round_id),
    KEY idx_round_archives_archived_by (archived_by),
    KEY idx_round_archives_archived_at (archived_at),
    CONSTRAINT fk_round_archives_round
        FOREIGN KEY (round_id) REFERENCES round_schedules(round_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_round_archives_admin
        FOREIGN KEY (archived_by) REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Seed Data

INSERT INTO users (user_id, username, email, password_hash, trust_score, is_admin, account_status) VALUES
(1, 'Lero', 'dummy1@gmail.com', '$2y$10$oGQ4VVH3nNOg0ReGyzUtT.O5U4C2H96UKnI4xRpfDNgjq.YOj2s6y', 100, FALSE, 'Active'),
(2, 'Eden', 'dummy2@gmail.com', '$2y$10$VhtplHuc.fyIh3.7PWBotOnMWCD2uYrNG8/21TyElnLaTqYZtB6y2', 100, FALSE, 'Active'),
(3, 'Han', 'Hen1@gmail.com', '$2y$10$deBxbOZQg7kV0bOARg1f5el5GfdElEk/UqBnebgvFsdxzrxP.ZvYu', 100, FALSE, 'Active'),
(4, 'Admin', 'admin123@gmail.com', '$2y$10$XRDgFPgouTO.AzkmCKb97.EDIho.pqYphJcA6BELpfL.lZwkZcT8e', 100, TRUE, 'Active'),
(5, 'Maya', 'maya@example.com', '$2y$10$oGQ4VVH3nNOg0ReGyzUtT.O5U4C2H96UKnI4xRpfDNgjq.YOj2s6y', 100, FALSE, 'Active'),
(6, 'Carlo', 'carlo@example.com', '$2y$10$oGQ4VVH3nNOg0ReGyzUtT.O5U4C2H96UKnI4xRpfDNgjq.YOj2s6y', 100, FALSE, 'Active'),
(7, 'Bea', 'bea@example.com', '$2y$10$oGQ4VVH3nNOg0ReGyzUtT.O5U4C2H96UKnI4xRpfDNgjq.YOj2s6y', 100, FALSE, 'Active')
ON DUPLICATE KEY UPDATE username=VALUES(username);

INSERT INTO paluwagan_groups (group_id, group_code, group_name, description_rules, contribution_amount, total_member_slots, late_fee_rate, payment_frequency, grace_period_hours, calculated_total_pot, first_payment_due, group_status) VALUES
(2, 'Toka-771-234', 'Tambayan Savings ’26', 'Rules for Tambayan Savings ’26', 1000.00, 7, 20.00, 'Weekly', 24, 7000.00, '2026-10-15 12:00:00', 'Frozen'),
(3, 'Toka-445-991', 'Sunrise Paluwagan', 'Rules for Sunrise Paluwagan', 500.00, 7, 10.00, 'Weekly', 12, 3500.00, '2026-10-15 12:00:00', 'Frozen'),
(4, 'Toka-301-101', 'Barkada Savings', 'Rules for Barkada Savings', 500.00, 5, 10.00, 'Weekly', 24, 2500.00, '2026-10-15 12:00:00', 'Active'),
(5, 'Toka-301-102', 'Family Fund', 'Rules for Family Fund', 1000.00, 4, 15.00, 'Monthly', 48, 4000.00, '2026-10-15 12:00:00', 'Active'),
(6, 'Toka-301-103', 'Weekend Savers', 'Rules for Weekend Savers', 300.00, 6, 5.00, 'Weekly', 24, 1800.00, '2026-10-15 12:00:00', 'Active'),
(7, 'Toka-301-104', 'Community Circle', 'Rules for Community Circle', 800.00, 5, 10.00, 'Monthly', 24, 4000.00, '2026-10-15 12:00:00', 'Active'),
(8, 'Toka-301-105', 'Office Savings', 'Rules for Office Savings', 600.00, 8, 15.00, 'Monthly', 24, 4800.00, '2026-10-15 12:00:00', 'Active'),
(9, 'Toka-201-101', 'Holiday Fund', 'Rules for Holiday Fund', 1000.00, 5, 20.00, 'Monthly', 24, 5000.00, '2026-08-01 12:00:00', 'Completed'),
(10, 'Toka-201-102', 'School Savings', 'Rules for School Savings', 500.00, 6, 10.00, 'Weekly', 24, 3000.00, '2026-07-01 12:00:00', 'Completed')
ON DUPLICATE KEY UPDATE group_name=VALUES(group_name);

INSERT INTO freeze_polls (poll_id, group_id, initiator_user_id, reason, poll_status) VALUES
(1, 2, 1, 'Pot Disbursement Shortage/Delay', 'Active'),
(2, 3, 2, 'Unverified Share', 'Active')
ON DUPLICATE KEY UPDATE reason=VALUES(reason);

INSERT INTO poll_votes (poll_id, user_id, vote_choice) VALUES
(1, 1, 'Yes'),
(1, 2, 'Yes'),
(1, 3, 'Yes'),
(1, 5, 'Yes'),
(1, 6, 'Yes'),
(2, 1, 'Yes'),
(2, 2, 'Yes'),
(2, 3, 'Yes')
ON DUPLICATE KEY UPDATE vote_choice=VALUES(vote_choice);

-- Preserve the freeze poll membership backfill from the consolidated migration.
-- This also covers any active polls and memberships added to the seed data.
INSERT IGNORE INTO freeze_poll_members (poll_id, user_id)
SELECT fp.poll_id, gm.user_id
FROM freeze_polls fp
JOIN paluwagan_groups pg ON pg.group_id = fp.group_id
JOIN group_members gm ON gm.group_id = fp.group_id
WHERE fp.poll_status = 'Active'
  AND pg.group_status = 'Active';


