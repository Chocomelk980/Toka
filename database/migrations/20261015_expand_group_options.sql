ALTER TABLE paluwagan_groups
    MODIFY payment_frequency ENUM(
        'Weekly', 'Biweekly', 'Monthly', 'Bimonthly', 'Quarterly', 'Semiannual'
    ) NOT NULL;

ALTER TABLE group_payment_channels
    MODIFY channel_type ENUM(
        'GCash', 'Maya', 'Bank Transfer', 'Cash', 'Cash on hand', 'Other'
    ) NULL;
