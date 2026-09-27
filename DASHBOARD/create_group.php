<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: ../LOGIN_PAGE/index.php');
    exit;
}

$draft = $_SESSION['draft_group'] ?? [];

$errors = $_SESSION['group_errors'] ?? [];
unset($_SESSION['group_errors']);

if (!isset($_SESSION['generated_group_code'])) {
    $_SESSION['generated_group_code'] = 'Toka' . strtoupper(bin2hex(random_bytes(6)));
}
if (!isset($_SESSION['group_form_token'])) {
    $_SESSION['group_form_token'] = bin2hex(random_bytes(32));
}

$channelTypes = $draft['channel_type'] ?? ['GCash', 'Maya'];
$channelNames = $draft['channel_name'] ?? ['', ''];
$channelNumbers = $draft['channel_number'] ?? ['', ''];

$username = $_SESSION['user']['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toka - Create New Group</title>
    <link rel="stylesheet" href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>">
</head>
<body>
    <div class="app">
        <aside class="sidebar">
            <div class="sidebar-top">
                <div class="brand">
                    <img src="../assets/Toka.svg" alt="Toka">
                </div>

                <nav class="nav">
                    <a href="my_groups.php" class="nav-item">
                        <span class="nav-icon">👥</span>
                        <span>My Groups</span>
                    </a>

                    <a href="#" class="nav-item">
                        <span class="nav-icon">⌂</span>
                        <span>Dashboard</span>
                    </a>
                </nav>
            </div>

            <div class="sidebar-bottom">
                <div class="profile">
                    <div class="profile-circle"><?= htmlspecialchars(strtoupper(substr($username, 0, 1))) ?></div>
                    <span><?= htmlspecialchars($username) ?></span>
                </div>

                <a href="../LOGIN_PAGE/logout.php" class="logout">↪</a>
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
                                <input id="group_name" name="group_name" type="text" required maxlength="100" value="<?= htmlspecialchars($draft['group_name'] ?? '') ?>">
                            </div>

                            <div class="field-group">
                                <label for="description">Description / House Rules</label>
                                <textarea id="description" name="description" rows="5"><?= htmlspecialchars($draft['description'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="form-column">
                            <h2>Capacity, Penalty &amp; Schedules</h2>

                            <div class="mini-grid">
                                <div class="field-group contribution-field">
                                    <label for="contribution_amount">Contribution Amount</label>
                                    <input id="contribution_amount" name="contribution_amount" type="number" required min="0.01" max="99999999.99" step="0.01" value="<?= htmlspecialchars($draft['contribution_amount'] ?? '') ?>">
                                </div>

                                <div class="field-group">
                                    <label for="total_member_slots">Total Member Slots</label>
                                    <input id="total_member_slots" name="total_member_slots" type="number" required min="1" max="2147483647" step="1" value="<?= htmlspecialchars($draft['total_member_slots'] ?? '') ?>">
                                </div>

                                <div class="field-group">
                                    <label for="late_fee_rate">Late Fee Rate</label>
                                    <input id="late_fee_rate" name="late_fee_rate" type="number" required min="0" max="99999999.99" step="0.01" value="<?= htmlspecialchars($draft['late_fee_rate'] ?? '') ?>">
                                </div>

                                <div class="field-group">
                                    <label for="calculated_total_pot">Calculated Total Pot</label>
                                    <input id="calculated_total_pot" name="calculated_total_pot" type="number" required min="0.01" max="99999999.99" step="0.01" value="<?= htmlspecialchars($draft['calculated_total_pot'] ?? '') ?>">
                                </div>

                                <div class="field-group">
                                    <label for="payment_frequency">Payment Frequency</label>
                                    <input id="payment_frequency" name="payment_frequency" type="text" required pattern="[Ww][Ee][Ee][Kk][Ll][Yy]|[Mm][Oo][Nn][Tt][Hh][Ll][Yy]" placeholder="Weekly or Monthly" title="Enter Weekly or Monthly" value="<?= htmlspecialchars($draft['payment_frequency'] ?? '') ?>">
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

                        <div id="channel-rows">
                            <?php for ($i = 0; $i < min(3, max(2, count($channelTypes))); $i++): ?>
                                <div class="channel-row">
                                    <div class="field-group channel-field">
                                        <label>Channel Type (Max 3)</label>
                                        <input type="text" name="channel_type[]" required maxlength="20" title="GCash, Maya, Bank Transfer, Cash, or Other" value="<?= htmlspecialchars($channelTypes[$i] ?? '') ?>">
                                    </div>

                                    <div class="field-group channel-field">
                                        <label>Name</label>
                                        <input type="text" name="channel_name[]" required maxlength="100" value="<?= htmlspecialchars($channelNames[$i] ?? '') ?>">
                                    </div>

                                    <div class="field-group channel-field">
                                        <label>Number</label>
                                        <input type="text" name="channel_number[]" required maxlength="50" value="<?= htmlspecialchars($channelNumbers[$i] ?? '') ?>">
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
                        <input type="text" name="channel_type[]" required maxlength="20" title="GCash, Maya, Bank Transfer, Cash, or Other" value="">
                    </div>
                    <div class="field-group channel-field">
                        <label>Name</label>
                        <input type="text" name="channel_name[]" required maxlength="100" value="">
                    </div>
                    <div class="field-group channel-field">
                        <label>Number</label>
                        <input type="text" name="channel_number[]" required maxlength="50" value="">
                    </div>
                `;
                channelRows.appendChild(row);
                addChannelBtn.disabled = channelRows.children.length >= 3;
            });
        }

        const form = document.querySelector('.create-form');
        const contribution = document.getElementById('contribution_amount');
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
        contribution.addEventListener('input', calculatePot);
        slots.addEventListener('input', calculatePot);

        function validateField(input) {
            input.setCustomValidity('');
            if (input.required && !input.value.trim()) {
                input.setCustomValidity('Please complete this field.');
            } else if (input.name === 'channel_type[]'
                && !['gcash', 'maya', 'paymaya', 'bank transfer', 'cash', 'other'].includes(input.value.trim().toLowerCase())) {
                input.setCustomValidity('Choose GCash, Maya, Bank Transfer, Cash, or Other.');
            }
        }
        form.addEventListener('input', event => {
            if (event.target.matches('input')) validateField(event.target);
        });
        form.addEventListener('submit', event => {
            form.querySelectorAll('input[required]').forEach(validateField);
            if (!form.reportValidity()) event.preventDefault();
        });
    </script>
</body>
</html>
