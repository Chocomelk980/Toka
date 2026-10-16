SET @claim_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'round_schedules'
      AND COLUMN_NAME = 'recipient_claimed_at'
);
SET @claim_column_sql := IF(
    @claim_column_exists = 0,
    'ALTER TABLE round_schedules ADD COLUMN recipient_claimed_at DATETIME NULL AFTER actual_disbursement_date',
    'SET @cycle_migration_noop = 1'
);
PREPARE cycle_claim_stmt FROM @claim_column_sql;
EXECUTE cycle_claim_stmt;
DEALLOCATE PREPARE cycle_claim_stmt;

SET @approval_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'round_schedules'
      AND COLUMN_NAME = 'operator_approved_at'
);
SET @approval_column_sql := IF(
    @approval_column_exists = 0,
    'ALTER TABLE round_schedules ADD COLUMN operator_approved_at DATETIME NULL AFTER recipient_claimed_at',
    'SET @cycle_migration_noop = 1'
);
PREPARE cycle_approval_stmt FROM @approval_column_sql;
EXECUTE cycle_approval_stmt;
DEALLOCATE PREPARE cycle_approval_stmt;
