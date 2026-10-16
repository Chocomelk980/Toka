-- Store registration names separately from usernames. Existing accounts may leave this empty.
SET @full_name_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'full_name'
);
SET @full_name_sql := IF(
    @full_name_exists = 0,
    'ALTER TABLE users ADD COLUMN full_name VARCHAR(150) NULL AFTER username',
    'SET @full_name_migration_noop = 1'
);
PREPARE full_name_stmt FROM @full_name_sql;
EXECUTE full_name_stmt;
DEALLOCATE PREPARE full_name_stmt;
