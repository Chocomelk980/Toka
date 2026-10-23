<?php

session_start();
require_once __DIR__ . '/../LOGIN_PAGE/access.php';
tokaRequireRole();
require_once __DIR__ . '/group_limits.php';
require_once __DIR__ . '/cycle_helpers.php';


require_once __DIR__ . '/../LOGIN_PAGE/dbconnect.php';



if (!isset($_SESSION['user']) || (int) ($_SESSION['user']['user_id'] ?? 0) < 1) {

    header('Location: ../LOGIN_PAGE/index.php');

    exit;

}



$draft = $_SESSION['draft_group'] ?? [];

$publishError = '';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

$isPublish = $isPost && isset($_POST['confirm_publish']);



if ($isPost) {

    $token = $_POST['group_form_token'] ?? '';

    if (!is_string($token) || !isset($_SESSION['group_form_token'])

        || !hash_equals($_SESSION['group_form_token'], $token)) {

        $_SESSION['group_errors'] = ['Your form has expired. Please review your details and try again.'];

        header('Location: create_group.php');

        exit;

    }



    // Publishing uses the exact draft that was reviewed, not editable hidden values.

    if (!$isPublish) {

        $draft = [];

        foreach (['group_name', 'description', 'contribution_amount', 'total_member_slots',
                  'late_fee_rate', 'payment_frequency',
                  'first_payment_due'] as $field) {
            $draft[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';

        }

        foreach (['channel_type', 'channel_name', 'channel_number'] as $field) {

            $values = $_POST[$field] ?? [];

            $draft[$field] = is_array($values)

                ? array_map(fn($value) => is_string($value) ? trim($value) : '', array_values($values))

                : [];

        }

        $draft['payment_frequency'] = ucfirst(strtolower($draft['payment_frequency']));

        $channelOptions = ['gcash' => 'GCash', 'maya' => 'Maya', 'paymaya' => 'Maya',

                           'bank transfer' => 'Bank Transfer', 'cash' => 'Cash',
                           'cash on hand' => 'Cash on hand', 'other' => 'Other'];

        foreach ($draft['channel_type'] as &$type) {

            $type = $channelOptions[strtolower($type)] ?? $type;

        }

        unset($type);

    }

}



if (!$draft) {
    header('Location: create_group.php');

    exit;

}

// Derive the pot from the source fields; never trust a posted total-pot value.
$draft['calculated_total_pot'] = '';
$contributionText = $draft['contribution_amount'] ?? '';
$slotText = $draft['total_member_slots'] ?? '';
if (is_string($contributionText) && preg_match('/^[1-9]\d{0,4}$/D', $contributionText)
    && is_string($slotText) && preg_match('/^[1-9]\d{0,9}$/D', $slotText)) {
    $memberSlots = (int) $slotText;
    $contributionCents = (int) $contributionText * 100;
    if ($memberSlots <= 12 && $contributionCents > 0 && $contributionCents <= 2000000
        && $contributionCents <= intdiv(9999999999, $memberSlots)) {
        $totalCents = $contributionCents * $memberSlots;
        $draft['calculated_total_pot'] = intdiv($totalCents, 100) . '.'
            . str_pad((string) ($totalCents % 100), 2, '0', STR_PAD_LEFT);
    }
}

$_SESSION['draft_group'] = $draft;


// Validate again on confirmation so incomplete drafts can never reach the database.

$errors = [];

$labels = [

    'group_name' => 'Group Name', 'contribution_amount' => 'Contribution Amount',

    'total_member_slots' => 'Total Member Slots', 'late_fee_rate' => 'Late Fee Rate',

    'calculated_total_pot' => 'Calculated Total Pot', 'payment_frequency' => 'Payment Frequency',

    'first_payment_due' => 'First Payment Due',
];

foreach ($labels as $field => $label) {

    if (!is_string($draft[$field] ?? null) || trim($draft[$field]) === '') {

        $errors[] = $label . ' is required.';

    }

}



if (mb_strlen($draft['group_name'] ?? '') > 100) {

    $errors[] = 'Group Name must be at most 100 characters.';

}

if (strlen($draft['description'] ?? '') > 65535) {

    $errors[] = 'Description is too long. Please shorten it.';

}



$cents = [];

foreach (['contribution_amount', 'late_fee_rate', 'calculated_total_pot'] as $field) {

    $value = $draft[$field] ?? '';

    if ($value === '') {

        continue;

    }

    if ($field === 'contribution_amount' || $field === 'late_fee_rate') {
        $minimumAmount = $field === 'contribution_amount' ? 1 : 0;
        $wholeAmountPattern = $minimumAmount === 0 ? '/^(?:0|[1-9]\d{0,4})$/D' : '/^[1-9]\d{0,4}$/D';
        if (!preg_match($wholeAmountPattern, $value) || (int) $value > 20000) {
            $errors[] = $labels[$field] . ' must be a whole number from ' . $minimumAmount . ' to 20,000.';
            continue;
        }

        $cents[$field] = (int) $value * 100;
    } elseif (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {

        $errors[] = $labels[$field] . ' must be between 0 and 99,999,999.99 with at most two decimal places.';

        continue;

    } else {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $cents[$field] = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    if ($field !== 'late_fee_rate' && $cents[$field] === 0) {

        $errors[] = $labels[$field] . ' must be greater than zero.';

    }

}



$integers = [];

foreach (['total_member_slots' => 1] as $field => $minimum) {
    $value = $draft[$field] ?? '';

    if ($value === '') {

        continue;

    }

    $integers[$field] = filter_var($value, FILTER_VALIDATE_INT, [

        'options' => ['min_range' => $minimum, 'max_range' => 12],

    ]);

    if ($integers[$field] === false) {

        $errors[] = $labels[$field] . ' must be a whole number from ' . $minimum . ' to 12.';

    }

}

if (isset($cents['contribution_amount'], $cents['calculated_total_pot'], $integers['total_member_slots'])

    && $integers['total_member_slots'] !== false

    && $cents['contribution_amount'] * $integers['total_member_slots'] !== $cents['calculated_total_pot']) {

    $errors[] = 'Calculated Total Pot must equal Contribution Amount multiplied by Total Member Slots.';

}



if (($draft['payment_frequency'] ?? '') !== ''

    && !array_key_exists($draft['payment_frequency'], tokaCycleFrequencyOptions())) {

    $errors[] = 'Choose a valid payment frequency.';

}



$dueValue = $draft['first_payment_due'] ?? '';

$due = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $dueValue)

    ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $dueValue) : false;

if (($draft['first_payment_due'] ?? '') !== ''

    && (!$due || $due->format('Y-m-d\TH:i') !== $draft['first_payment_due']

        || (int) $due->format('Y') < 1000)) {

    $errors[] = 'First Payment Due must be a valid date and time.';

}



$channelTypes = $draft['channel_type'] ?? [];

$channelNames = $draft['channel_name'] ?? [];

$channelNumbers = $draft['channel_number'] ?? [];

$channelCount = max(count($channelTypes), count($channelNames), count($channelNumbers));

if ($channelCount > 3

    || count($channelTypes) !== $channelCount || count($channelNames) !== $channelCount

    || count($channelNumbers) !== $channelCount) {

    $errors[] = 'Enter up to three complete payment channels, or leave them blank to use Cash on hand.';

}

$configuredChannels = [];
$allowedChannelTypes = ['GCash', 'Maya', 'Bank Transfer', 'Cash', 'Cash on hand', 'Other'];
for ($i = 0; $i < min($channelCount, 3); $i++) {
    $type = trim((string) ($channelTypes[$i] ?? ''));
    $name = trim((string) ($channelNames[$i] ?? ''));
    $number = trim((string) ($channelNumbers[$i] ?? ''));

    // An omitted channel type explicitly defaults to the cash-on-hand option.
    if ($type === '') $type = 'Cash on hand';

    // An untouched row is not a configured channel.
    if ($name === '' && $number === '') continue;

    $rowLabel = 'Payment channel ' . ($i + 1);
    $rowHasError = false;
    if (!in_array($type, $allowedChannelTypes, true)) {
        $errors[] = $rowLabel . ': choose GCash, Maya, Bank Transfer, Cash, Cash on hand, or Other.';
        $rowHasError = true;
    }

    $cashOnHand = $type === 'Cash on hand';
    if ((!$cashOnHand && $name === '') || mb_strlen($name) > 100) {
        $errors[] = $rowLabel . ': an account name of at most 100 characters is required.';
        $rowHasError = true;
    }
    if (!$cashOnHand && $number === '') {
        $errors[] = $rowLabel . ': an 11-digit account number is required.';
        $rowHasError = true;
    } elseif ($number !== '' && !preg_match('/^[0-9]{11}$/D', $number)) {
        $errors[] = $rowLabel . ': account number must contain exactly 11 digits.';
        $rowHasError = true;
    }

    if (!$rowHasError) {
        $configuredChannels[] = [
            'channel_type' => $type,
            'channel_name' => $name,
            'channel_number' => $number,
        ];
    }
}



if ($errors) {

    $_SESSION['group_errors'] = $errors;

    header('Location: create_group.php');

    exit;

}

if (!$configuredChannels) {
    $configuredChannels[] = [
        'channel_type' => 'Cash on hand',
        'channel_name' => '',
        'channel_number' => '',
    ];
}
$channelTypes = array_column($configuredChannels, 'channel_type');
$channelNames = array_column($configuredChannels, 'channel_name');
$channelNumbers = array_column($configuredChannels, 'channel_number');
$draft['channel_type'] = $channelTypes;
$draft['channel_name'] = $channelNames;
$draft['channel_number'] = $channelNumbers;
$_SESSION['draft_group'] = $draft;



if (!isset($_SESSION['generated_group_code'])) {

    $_SESSION['generated_group_code'] = 'Toka' . strtoupper(bin2hex(random_bytes(6)));

}



if ($isPublish) {

    $pdo = null;

    try {

        $pdo = getDbConnection();

        $pdo->beginTransaction();

        tokaLockGroupLimitUser($pdo, (int) $_SESSION['user']['user_id']);
        $limitError = tokaGroupLimitError($pdo, (int) $_SESSION['user']['user_id']);
        if ($limitError !== null) {
            throw new TokaGroupLimitException($limitError);
        }

        $stmt = $pdo->prepare(

            'INSERT INTO paluwagan_groups

             (group_code, group_name, description_rules, contribution_amount, total_member_slots,

              late_fee_rate, payment_frequency, grace_period_hours, calculated_total_pot,

              first_payment_due, group_status)

             VALUES (:code, :name, :description, :amount, :slots, :fee, :frequency,
                     72, :pot, :due, :status)'
        );

        $stmt->execute([

            ':code' => $_SESSION['generated_group_code'], ':name' => $draft['group_name'],

            ':description' => $draft['description'], ':amount' => $draft['contribution_amount'],

            ':slots' => $integers['total_member_slots'], ':fee' => $draft['late_fee_rate'],

            ':frequency' => $draft['payment_frequency'],
            ':pot' => $draft['calculated_total_pot'], ':due' => $due->format('Y-m-d H:i:s'),

            ':status' => 'Waiting',

        ]);

        $groupId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(

            'INSERT INTO group_members (group_id, user_id, role, assigned_slot_number)

             VALUES (:group_id, :user_id, :role, 1)'

        );

        $stmt->execute([

            ':group_id' => $groupId, ':user_id' => (int) $_SESSION['user']['user_id'],

            ':role' => 'Main Operator',

        ]);

        $stmt = $pdo->prepare(

            'INSERT INTO group_payment_channels (group_id, channel_type, account_name, account_number)

             VALUES (:group_id, :type, :name, :number)'

        );

        foreach ($channelTypes as $i => $type) {

            $stmt->execute([

                ':group_id' => $groupId, ':type' => $type,

                ':name' => $channelNames[$i], ':number' => $channelNumbers[$i],

            ]);

        }

        $auditStmt = $pdo->prepare(
            'INSERT INTO audit_logs
             (group_id, actor_user_id, activity_type, details, verification_status)
             VALUES (:group_id, :actor, :activity, :details, :verification)'
        );
        $auditStmt->execute([
            ':group_id' => $groupId,
            ':actor' => (int) $_SESSION['user']['user_id'],
            ':activity' => 'Group Started',
            ':details' => 'Group created by Main Operator',
            ':verification' => 'System Automated',
        ]);
        $pdo->commit();

        unset($_SESSION['draft_group'], $_SESSION['generated_group_code'], $_SESSION['group_form_token']);

        $_SESSION['group_success'] = 'Group created successfully.';

        header('Location: my_groups.php?tab=Waiting');

        exit;

    } catch (TokaGroupLimitException $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        $publishError = $e->getMessage();
    } catch (PDOException $e) {

        if ($pdo && $pdo->inTransaction()) {

            $pdo->rollBack();

        }

        error_log('Group creation DB error: ' . $e->getMessage());

        $publishError = 'We could not save your group. Your details have been kept. Please try again.';

        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {

            $_SESSION['generated_group_code'] = 'Toka' . strtoupper(bin2hex(random_bytes(6)));

            $publishError = 'That group code was already taken. A new code is shown below. Please confirm again.';

        }

    }

}



$username = $_SESSION['user']['username'] ?? 'User';

$groupCode = $_SESSION['generated_group_code'];

?>

<!DOCTYPE html>

<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toka - Review Group</title>
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

                        <span class="nav-icon"><i class="fa-solid fa-border-all" aria-hidden="true"></i></span>

                        <span>Dashboard</span>

                    </a>



                    <a href="#" class="nav-item" style="color: #c7c7c7;">

                        <span class="nav-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span>

                        <span>My Groups</span>

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



        <main class="main review-main">

            <div class="review-shell">

                <h1 class="review-title">Review Group Creation</h1>

                <p class="review-subtitle">Verify details and save your group identifier code.</p>



                <?php if ($publishError !== ''): ?>

                    <div class="group-message group-error" role="alert"><?= htmlspecialchars($publishError) ?></div>

                <?php endif; ?>



                <div class="review-code-box">

                    <div class="code-label">GENERATED GROUP CODE</div>

                    <div class="code-value"><?= htmlspecialchars($groupCode) ?></div>

                    <button type="button" class="copy-code-btn" aria-live="polite"><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy Code</button>

                </div>



                <div class="review-grid">

                    <div class="review-card">

                        <h3>PROFILE DETAILS</h3>

                        <div class="review-row">

                            <span>Group Name</span>

                            <strong><?= htmlspecialchars($draft['group_name'] ?? '') ?></strong>

                        </div>

                        <div class="review-row">

                            <span>Description / House Rules</span>

                            <strong><?= htmlspecialchars($draft['description'] ?? '') ?></strong>

                        </div>

                    </div>



                    <div class="review-card">

                        <h3>CAPACITY, FEES &amp; SCHEDULES</h3>

                        <div class="review-row"><span>Contribution</span><strong><?= htmlspecialchars($draft['contribution_amount'] ?? '') ?></strong></div>

                        <div class="review-row"><span>Total Slots</span><strong><?= htmlspecialchars($draft['total_member_slots'] ?? '') ?></strong></div>

                        <div class="review-row"><span>Total Pot</span><strong><?= htmlspecialchars($draft['calculated_total_pot'] ?? '') ?></strong></div>

                        <div class="review-row"><span>Frequency</span><strong><?= htmlspecialchars($draft['payment_frequency'] ?? '') ?></strong></div>

                        <div class="review-row"><span>First Payment</span><strong><?= htmlspecialchars($draft['first_payment_due'] ?? '') ?></strong></div>

                        <div class="review-row"><span>Late Fee Rate</span><strong><?= htmlspecialchars($draft['late_fee_rate'] ?? '') ?></strong></div>

                    </div>



                    <div class="review-card">

                        <h3>PAYMENT CHANNELS</h3>

                        <?php if (empty(array_filter($channelTypes, fn($value) => trim((string)$value) !== ''))): ?>

                            <p class="no-channels">No channels configured</p>

                        <?php else: ?>

                            <?php foreach ($channelTypes as $i => $type): ?>

                                <?php if (trim((string) $type) !== ''): ?>

                                    <div class="review-row small"><span><?= htmlspecialchars($type) ?></span><strong><?= htmlspecialchars($channelNames[$i] ?? '') ?></strong></div>

                                <?php endif; ?>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>



                <div class="review-actions">

                    <a href="create_group.php" class="secondary-btn">Back to Edit</a>

                    <form method="post" action="review_group.php" class="inline-form">

                        <input type="hidden" name="confirm_publish" value="1">

                        <input type="hidden" name="group_form_token" value="<?= htmlspecialchars($_SESSION['group_form_token']) ?>">

                        <input type="hidden" name="group_name" value="<?= htmlspecialchars($draft['group_name'] ?? '') ?>">

                        <input type="hidden" name="description" value="<?= htmlspecialchars($draft['description'] ?? '') ?>">

                        <input type="hidden" name="contribution_amount" value="<?= htmlspecialchars($draft['contribution_amount'] ?? '') ?>">

                        <input type="hidden" name="total_member_slots" value="<?= htmlspecialchars($draft['total_member_slots'] ?? '') ?>">

                        <input type="hidden" name="late_fee_rate" value="<?= htmlspecialchars($draft['late_fee_rate'] ?? '') ?>">

                        <input type="hidden" name="calculated_total_pot" value="<?= htmlspecialchars($draft['calculated_total_pot'] ?? '') ?>">

                        <input type="hidden" name="payment_frequency" value="<?= htmlspecialchars($draft['payment_frequency'] ?? '') ?>">

                        <input type="hidden" name="first_payment_due" value="<?= htmlspecialchars($draft['first_payment_due'] ?? '') ?>">

                        <?php foreach ($channelTypes as $i => $type): ?>

                            <input type="hidden" name="channel_type[]" value="<?= htmlspecialchars($type) ?>">

                            <input type="hidden" name="channel_name[]" value="<?= htmlspecialchars($channelNames[$i] ?? '') ?>">

                            <input type="hidden" name="channel_number[]" value="<?= htmlspecialchars($channelNumbers[$i] ?? '') ?>">

                        <?php endforeach; ?>

                        <button type="submit" class="primary-btn">Confirm &amp; Publish Group</button>

                    </form>

                </div>

            </div>

        </main>

    </div>

<script>
    const copyCodeButton = document.querySelector('.copy-code-btn');
    const groupCodeValue = document.querySelector('.code-value');
    const originalCopyLabel = copyCodeButton?.innerHTML ?? '';

    copyCodeButton?.addEventListener('click', async () => {
        const code = groupCodeValue?.textContent.trim();
        if (!code) return;

        let copied = false;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(code);
                copied = true;
            }
        } catch (error) {
            // Fall back for browsers or contexts that block the Clipboard API.
        }

        if (!copied) {
            const temporaryField = document.createElement('textarea');
            temporaryField.value = code;
            temporaryField.setAttribute('readonly', '');
            temporaryField.style.position = 'fixed';
            temporaryField.style.opacity = '0';
            document.body.appendChild(temporaryField);
            temporaryField.focus();
            temporaryField.select();
            try {
                copied = document.execCommand('copy');
            } catch (error) {
                copied = false;
            }
            temporaryField.remove();
        }

        copyCodeButton.textContent = copied ? 'Copied!' : 'Unable to copy';
        window.setTimeout(() => {
            copyCodeButton.innerHTML = originalCopyLabel;
        }, 1800);
    });
</script>
<script src="../LOGIN_PAGE/tab-auth.js?v=<?= filemtime(__DIR__ . '/../LOGIN_PAGE/tab-auth.js') ?>"></script>
</body>

</html>



