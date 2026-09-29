ALTER TABLE payments
    ADD COLUMN is_cooperator_verified BOOLEAN NOT NULL DEFAULT 0
    AFTER is_member_confirmed;

UPDATE payments p
JOIN group_members gm ON gm.member_id = p.member_id
SET p.is_cooperator_verified = CASE
        WHEN p.payment_status = 'Verified' OR gm.role = 'Co-Operator' THEN 1
        ELSE p.is_cooperator_verified
    END,
    p.is_operator_verified = CASE
        WHEN p.payment_status = 'Verified' OR gm.role = 'Main Operator' THEN 1
        ELSE p.is_operator_verified
    END
WHERE p.payment_status IN ('Pending Verification', 'Verified');
