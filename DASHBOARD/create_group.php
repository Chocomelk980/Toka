<?php
session_start();
require_once __DIR__ . '/../LOGIN_PAGE/access.php';
tokaRequireRole();
require_once __DIR__ . '/group_limits.php';
require_once __DIR__ . '/cycle_helpers.php';

if (!isset($_SESSION['user'])) {
    header('Location: ../LOGIN_PAGE/index.php');
    exit;
}

try {
    $limitError = tokaGroupLimitError(getDbConnection(), (int) $_SESSION['user']['user_id']);
} catch (PDOException $e) {
    error_log('Group limit check failed: ' . $e->getMessage());
    $limitError = 'We could not check your group limit right now. Please try again.';
}
if ($limitError !== null) {
    $_SESSION['group_limit_error'] = $limitError;
    header('Location: my_groups.php', true, 303);
    exit;
}

$draft = $_SESSION['draft_group'] ?? [];

// Discard stale fractional or out-of-range contribution drafts so the stepper starts cleanly.
$contributionDraft = $draft['contribution_amount'] ?? '';
if (!is_string($contributionDraft) || !preg_match('/^[1-9]\d{0,4}$/D', $contributionDraft)
    || (int) $contributionDraft > 20000) {
    $draft['contribution_amount'] = '';
}
$lateFeeDraft = $draft['late_fee_rate'] ?? '';
if (!is_string($lateFeeDraft) || !preg_match('/^(?:0|[1-9]\d{0,4})$/D', $lateFeeDraft)
    || (int) $lateFeeDraft > 20000) {
    $draft['late_fee_rate'] = '';
}

function createGroupCalculatedPot(string $contribution, string $slots): string
{
    if (!preg_match('/^[1-9]\d{0,4}$/D', $contribution)
        || !preg_match('/^[1-9]\d{0,9}$/D', $slots)) return '';

    $memberSlots = (int) $slots;
    if ($memberSlots > 12) return '';

    $contributionCents = (int) $contribution * 100;
    if ($contributionCents < 1 || $contributionCents > 2000000
        || $contributionCents > intdiv(9999999999, $memberSlots)) return '';

    $totalCents = $contributionCents * $memberSlots;
    return intdiv($totalCents, 100) . '.' . str_pad((string) ($totalCents % 100), 2, '0', STR_PAD_LEFT);
}

// Never display a total restored from a submitted form or an old draft.
$draft['calculated_total_pot'] = createGroupCalculatedPot(
    (string) ($draft['contribution_amount'] ?? ''),
    (string) ($draft['total_member_slots'] ?? '')
);

$errors = $_SESSION['group_errors'] ?? [];
unset($_SESSION['group_errors']);

if (!isset($_SESSION['generated_group_code'])) {
    $_SESSION['generated_group_code'] = 'Toka' . strtoupper(bin2hex(random_bytes(6)));
}
if (!isset($_SESSION['group_form_token'])) {
    $_SESSION['group_form_token'] = bin2hex(random_bytes(32));
}

$channelTypes = $draft['channel_type'] ?? ['', ''];
$channelNames = $draft['channel_name'] ?? ['', ''];
$channelNumbers = $draft['channel_number'] ?? ['', ''];
$paymentFrequencyOptions = tokaCycleFrequencyOptions();

$username = $_SESSION['user']['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toka - Create New Group</title>
    <link rel="stylesheet" href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>">
    <link rel="stylesheet" href="../assets/typography.css?v=<?= filemtime(__DIR__ . '/../assets/typography.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/svg+xml" href="../assets/toka_icon.svg">
</head>
<body>
    <div class="app">
        <aside class="sidebar">
            <div class="sidebar-top">
                <div class="brand">
                    <img src="../assets/Toka.svg" alt="Toka">
                </div>

                <nav class="nav">
                    <a href="my_groups.php" class="nav-item active">
                        <span class="nav-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
                        <span>My Groups</span>
                    </a>

                    <a href="#" class="nav-item" style="color: #c7c7c7;">
                        <span class="nav-icon"><i class="fa-solid fa-border-all" aria-hidden="true"></i></span>
                        <span>Dashboard</span>
                    </a>
                </nav>
            </div>

            <div class="sidebar-bottom">
                <div class="profile">
                    <div class="profile-circle"><?= htmlspecialchars(strtoupper(substr($username, 0, 1))) ?></div>
                    <span><?= htmlspecialchars($username) ?></span>
                </div>

                <a href="../LOGIN_PAGE/logout.php" class="logout" aria-label="Log out" title="Log out"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i></a>
            </div>
        </aside>

        <main class="main create-main">
            <div class="create-shell">
                <h1 class="create-title">Create New Group</h1>

                <?php if ($errors): ?>
                    <div class="group-message group-error" role="alert">
                        <strong>Please check your group details:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form class="create-form" method="post" action="review_group.php">
                    <input type="hidden" name="group_form_token" value="<?= htmlspecialchars($_SESSION['group_form_token']) ?>">
                    <div class="create-grid">
                        <div class="form-column">
                            <h2>Profile</h2>

                            <div class="field-group">
                                <label for="group_name">Group Name</label>
                                <input id="group_name" name="group_name" type="text" autocomplete="off" required maxlength="100" value="<?= htmlspecialchars($draft['group_name'] ?? '') ?>">
                            </div>

                            <div class="field-group">
                                <label for="description">Description / House Rules</label>
                                <textarea id="description" name="description" autocomplete="off" rows="5"><?= htmlspecialchars($draft['description'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="form-column">
                            <h2>Capacity, Penalty &amp; Schedules</h2>

                            <div class="mini-grid">
                                <div class="field-group contribution-field">
                                    <label for="contribution_amount">Contribution Amount</label>
                                    <input id="contribution_amount" name="contribution_amount" type="number" required min="1" max="20000" step="1" value="<?= htmlspecialchars($draft['contribution_amount'] ?? '') ?>">
                                </div>

                                <div class="field-group">
                                    <label for="total_member_slots">Total Member Slots</label>
                                    <input id="total_member_slots" name="total_member_slots" type="number" required min="1" max="12" step="1" value="<?= htmlspecialchars($draft['total_member_slots'] ?? '') ?>">
                                </div>

                                <div class="field-group">
                                    <label for="late_fee_rate">Late Fee Rate</label>
                                    <input id="late_fee_rate" name="late_fee_rate" type="number" required min="0" max="20000" step="1" value="<?= htmlspecialchars($draft['late_fee_rate'] ?? '') ?>">
                                </div>

                                <div class="field-group">
                                    <label for="calculated_total_pot">Calculated Total Pot</label>
                                    <input id="calculated_total_pot" type="number" required min="0.01" max="99999999.99" step="0.01" value="<?= htmlspecialchars($draft['calculated_total_pot']) ?>" readonly aria-readonly="true" title="Calculated automatically from the contribution amount and member slots">
                                </div>

                                <div class="field-group">
                                    <label for="payment_frequency">Payment Frequency</label>
                                    <select id="payment_frequency" class="filter-style-select" name="payment_frequency" required>
                                        <option value="" disabled hidden <?= empty($draft['payment_frequency']) ? 'selected' : '' ?>>Select</option>
                                        <?php foreach ($paymentFrequencyOptions as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= ($draft['payment_frequency'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="field-group">
                                    <label for="first_payment_due">First Payment Due</label>
                                    <input id="first_payment_due" name="first_payment_due" type="datetime-local" required min="1000-01-01T00:00" max="9999-12-31T23:59" value="<?= htmlspecialchars($draft['first_payment_due'] ?? '') ?>">
                                </div>

                            </div>
                        </div>
                    </div>

                    <div class="payment-panel">
                        <h2>Payment Channels</h2>
                        <p class="channel-note">Fill in a payment channel, or leave Channel Type blank to use Cash on hand automatically.</p>

                        <div id="channel-rows">
                            <?php for ($i = 0; $i < min(3, max(2, count($channelTypes))); $i++): ?>
                                <div class="channel-row">
                                    <div class="field-group channel-field">
                                        <label>Channel Type (Max 3)</label>
                                        <input type="text" name="channel_type[]" autocomplete="off" maxlength="20" title="GCash, Maya, Bank Transfer, Cash, Cash on hand, or Other" value="<?= htmlspecialchars($channelTypes[$i] ?? '') ?>">
                                    </div>

                                    <div class="field-group channel-field">
                                        <label>Name</label>
                                        <input type="text" name="channel_name[]" autocomplete="off" maxlength="100" value="<?= htmlspecialchars($channelNames[$i] ?? '') ?>">
                                    </div>

                                    <div class="field-group channel-field">
                                        <label>Number</label>
                                        <input type="text" name="channel_number[]" autocomplete="off" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" title="Enter exactly 11 digits." value="<?= htmlspecialchars($channelNumbers[$i] ?? '') ?>">
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <button class="add-channel" type="button">+ Add</button>
                    </div>

                    <div class="form-actions">
                        <a href="my_groups.php" class="secondary-btn">Back</a>
                        <button type="submit" class="primary-btn">Save and Create</button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        const addChannelBtn = document.querySelector('.add-channel');
        const channelRows = document.getElementById('channel-rows');

        if (addChannelBtn && channelRows) {
            addChannelBtn.disabled = channelRows.children.length >= 3;
            addChannelBtn.addEventListener('click', function () {
                if (channelRows.children.length >= 3) return;
                const row = document.createElement('div');
                row.className = 'channel-row';
                row.innerHTML = `
                    <div class="field-group channel-field">
                        <label>Channel Type (Max 3)</label>
                        <input type="text" name="channel_type[]" autocomplete="off" maxlength="20" title="GCash, Maya, Bank Transfer, Cash, Cash on hand, or Other" value="">
                    </div>
                    <div class="field-group channel-field">
                        <label>Name</label>
                        <input type="text" name="channel_name[]" autocomplete="off" maxlength="100" value="">
                    </div>
                    <div class="field-group channel-field">
                        <label>Number</label>
                        <input type="text" name="channel_number[]" autocomplete="off" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" title="Enter exactly 11 digits." value="">
                    </div>
                `;
                channelRows.appendChild(row);
                addChannelBtn.disabled = channelRows.children.length >= 3;
            });
        }

        const form = document.querySelector('.create-form');
        const contribution = document.getElementById('contribution_amount');
        const lateFeeRate = document.getElementById('late_fee_rate');
        const slots = document.getElementById('total_member_slots');
        const pot = document.getElementById('calculated_total_pot');

        function calculatePot() {
            validateField(contribution);
            validateField(slots);
            if (contribution.value && slots.value && contribution.validity.valid && slots.validity.valid) {
                pot.value = (Math.round(Number(contribution.value) * 100) * Number(slots.value) / 100).toFixed(2);
            } else {
                pot.value = '';
            }
            validateField(pot);
        }
        contribution.addEventListener('input', () => {
            if (Number(contribution.value) > 20000) {
                contribution.value = '20000';
            }
            calculatePot();
        });
        lateFeeRate.addEventListener('input', () => {
            if (Number(lateFeeRate.value) > 20000) {
                lateFeeRate.value = '20000';
            }
        });
        slots.addEventListener('input', calculatePot);

        function validateField(input) {
            input.setCustomValidity('');
            if (input.required && !input.value.trim()) {
                input.setCustomValidity('Please complete this field.');
            } else if (input.name === 'channel_type[]' && input.value.trim() !== ''
                && !['gcash', 'maya', 'paymaya', 'bank transfer', 'cash', 'cash on hand', 'other'].includes(input.value.trim().toLowerCase())) {
                input.setCustomValidity('Choose GCash, Maya, Bank Transfer, Cash, Cash on hand, or Other.');
            } else if (input.name === 'channel_number[]' && input.value !== '' && !/^[0-9]{11}$/.test(input.value)) {
                input.setCustomValidity('Enter exactly 11 digits.');
            }
        }
        form.addEventListener('input', event => {
            if (event.target.matches('input[name="channel_number[]"]')) {
                event.target.value = event.target.value.replace(/[^0-9]/g, '').slice(0, 11);
            }
            if (event.target.matches('input')) validateField(event.target);
        });
        form.addEventListener('submit', event => {
            form.querySelectorAll('input[required]').forEach(validateField);
            if (!form.reportValidity()) event.preventDefault();
        });
    </script>
<script src="../LOGIN_PAGE/tab-auth.js?v=<?= filemtime(__DIR__ . '/../LOGIN_PAGE/tab-auth.js') ?>"></script>
</body>
</html>
