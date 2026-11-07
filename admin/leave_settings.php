<?php
session_start();

/* =========================================================
   ADMIN ACCESS CHECK
========================================================= */
if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit();
}
require_once "../db.php";

/* =========================================================
   SETTINGS TABLE
   The table is created automatically if it does not exist.
========================================================= */
$createTableSql = "
    CREATE TABLE IF NOT EXISTS leave_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

if (!$conn->query($createTableSql)) {
    die("Unable to prepare leave settings: " . htmlspecialchars($conn->error));
}

$defaultSettings = [
    "cl_default_days" => "20",
    "el_duty_days_divisor" => "11",
    "el_max_balance" => "300"
];

$insertSetting = $conn->prepare(
    "INSERT IGNORE INTO leave_settings (setting_key, setting_value) VALUES (?, ?)"
);

foreach ($defaultSettings as $key => $value) {
    $insertSetting->bind_param("ss", $key, $value);
    if (!$insertSetting->execute()) {
        die("Unable to initialize leave settings.");
    }
}
$insertSetting->close();

$message = "";
$messageType = "";

/* =========================================================
   SAVE SETTINGS
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $clDays = filter_input(INPUT_POST, "cl_default_days", FILTER_VALIDATE_INT);
    $elDivisor = filter_input(INPUT_POST, "el_duty_days_divisor", FILTER_VALIDATE_INT);
    $elMax = filter_input(INPUT_POST, "el_max_balance", FILTER_VALIDATE_INT);

    if ($clDays === false || $clDays === null || $clDays < 0 || $clDays > 365) {
        $message = "Casual Leave default must be a whole number from 0 to 365.";
        $messageType = "error";
    } elseif ($elDivisor === false || $elDivisor === null || $elDivisor < 1 || $elDivisor > 366) {
        $message = "The EL duty-day divisor must be a whole number from 1 to 366.";
        $messageType = "error";
    } elseif ($elMax === false || $elMax === null || $elMax < 0 || $elMax > 9999) {
        $message = "The EL maximum balance must be a whole number from 0 to 9999.";
        $messageType = "error";
    } else {
        $settingsToSave = [
            "cl_default_days" => (string)$clDays,
            "el_duty_days_divisor" => (string)$elDivisor,
            "el_max_balance" => (string)$elMax
        ];

        $updateSetting = $conn->prepare(
            "INSERT INTO leave_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        $conn->begin_transaction();
        $saveOkay = true;

        foreach ($settingsToSave as $key => $value) {
            $updateSetting->bind_param("ss", $key, $value);
            if (!$updateSetting->execute()) {
                $saveOkay = false;
                break;
            }
        }

        if ($saveOkay) {
            $conn->commit();
            $message = "Leave settings saved successfully.";
            $messageType = "success";
        } else {
            $conn->rollback();
            $message = "Could not save the settings. Please try again.";
            $messageType = "error";
        }

        $updateSetting->close();
    }
}

/* =========================================================
   LOAD CURRENT SETTINGS
========================================================= */
$currentSettings = $defaultSettings;
$result = $conn->query("SELECT setting_key, setting_value FROM leave_settings");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (array_key_exists($row["setting_key"], $currentSettings)) {
            $currentSettings[$row["setting_key"]] = $row["setting_value"];
        }
    }
    $result->free();
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Settings | EMMRC Leave Management</title>
    <link rel="stylesheet" href="../css/style.css?v=2">
    <style>
        .settings-page { max-width: 980px; margin: 0 auto; padding: 24px 18px 50px; }
        .settings-back { display: inline-block; margin-bottom: 18px; color: #526ee8; text-decoration: none; font-size: 14px; font-weight: 600; }
        .settings-back:hover { text-decoration: underline; }
        .settings-heading { margin-bottom: 22px; }
        .settings-heading h1 { margin: 0 0 8px; color: #243653; font-size: clamp(24px, 3vw, 32px); }
        .settings-heading p { margin: 0; color: #6b7890; font-size: 14px; line-height: 1.6; }
        .settings-card { background: #fff; border: 1px solid #e3e8f2; border-radius: 16px; padding: 24px; box-shadow: 0 8px 24px rgba(32, 55, 95, .06); }
        .settings-section-title { margin: 0 0 6px; color: #263653; font-size: 18px; }
        .settings-section-description { margin: 0 0 22px; color: #758198; font-size: 13px; line-height: 1.6; }
        .settings-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .setting-field { min-width: 0; }
        .setting-field label { display: block; margin-bottom: 8px; color: #34435d; font-size: 13px; font-weight: 700; }
        .setting-field input { box-sizing: border-box; width: 100%; min-height: 44px; padding: 10px 12px; border: 1px solid #d7deeb; border-radius: 9px; background: #fff; color: #263653; font: inherit; }
        .setting-field input:focus { outline: 2px solid rgba(82, 110, 232, .18); border-color: #526ee8; }
        .setting-help { margin: 7px 0 0; color: #7a879b; font-size: 12px; line-height: 1.5; }
        .settings-note { margin-top: 22px; padding: 14px 16px; border: 1px solid #dce6fb; border-radius: 10px; background: #f5f8ff; color: #455777; font-size: 13px; line-height: 1.65; }
        .settings-message { margin-bottom: 18px; padding: 12px 14px; border-radius: 9px; font-size: 13px; }
        .settings-message.success { background: #e9f8ef; border: 1px solid #bce8cb; color: #176b3b; }
        .settings-message.error { background: #fff0f0; border: 1px solid #f0c4c4; color: #a12727; }
        .settings-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }
        .settings-button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 0 18px; border: 0; border-radius: 9px; background: #526ee8; color: #fff; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .settings-button:hover { background: #405bd0; }
        .settings-secondary { border: 1px solid #d8dfec; background: #fff; color: #465570; }
        .settings-secondary:hover { background: #f5f7fb; }
        .settings-pending { margin-top: 20px; padding-top: 18px; border-top: 1px solid #e8edf5; }
        .settings-pending h3 { margin: 0 0 8px; font-size: 15px; color: #34435d; }
        .settings-pending p { margin: 0; font-size: 13px; color: #718097; line-height: 1.65; }
        @media (max-width: 640px) {
            .settings-page { padding: 18px 12px 36px; }
            .settings-card { padding: 18px; }
            .settings-grid { grid-template-columns: 1fr; }
            .settings-actions { flex-direction: column-reverse; }
            .settings-button { width: 100%; box-sizing: border-box; }
        }
    </style>

    <link rel="stylesheet" href="../css/responsive.css?v=1">
</head>
<body class="admin-page">
<header class="admin-header">
    <div class="admin-brand">
        <div class="admin-logo">
            <img src="../assets/emmrc logo.png" alt="EMMRC Logo">
        </div>
        <div class="admin-brand-divider"></div>
        <div class="admin-brand-text">
            <strong>Leave Management</strong>
            <span>Admin Portal</span>
        </div>
    </div>
    <div class="admin-user">
        <div class="admin-avatar">A</div>
        <div class="admin-user-details">
            <strong>Admin</strong>
            <span>Administrator</span>
        </div>
        <a href="../login.php?action=logout" class="admin-logout">↪ Logout</a>
    </div>
</header>

<main class="settings-page">
    <a href="admin.php" class="settings-back">← Back to Dashboard</a>

    <div class="settings-heading">
        <h1>Leave Settings</h1>
        <p>Configure the default leave policy values used by the leave management system.</p>
    </div>

    <?php if ($message !== ""): ?>
        <div class="settings-message <?php echo h($messageType); ?>" role="status">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="settings-card">
        <h2 class="settings-section-title">Default leave policy</h2>
        <p class="settings-section-description">Changes are saved in the database and can be adjusted by an administrator.</p>

        <div class="settings-grid">
            <div class="setting-field">
                <label for="cl_default_days">Casual Leave (CL) default days</label>
                <input type="number" id="cl_default_days" name="cl_default_days" min="0" max="365" step="1" required value="<?php echo h($currentSettings["cl_default_days"]); ?>">
                <p class="setting-help">Default for a newly added employee. Initial value: 20 days.</p>
            </div>

            <div class="setting-field">
                <label for="el_duty_days_divisor">EL accrual divisor</label>
                <input type="number" id="el_duty_days_divisor" name="el_duty_days_divisor" min="1" max="366" step="1" required value="<?php echo h($currentSettings["el_duty_days_divisor"]); ?>">
                <p class="setting-help">EL accrual is based on eligible duty days divided by this number. Proposed initial value: 11.</p>
            </div>

            <div class="setting-field">
                <label for="el_max_balance">Maximum Earned Leave (EL) balance</label>
                <input type="number" id="el_max_balance" name="el_max_balance" min="0" max="9999" step="1" required value="<?php echo h($currentSettings["el_max_balance"]); ?>">
                <p class="setting-help">Maximum EL balance allowed. Proposed initial value: 300 days.</p>
            </div>
        </div>

        <div class="settings-note">
            <strong>Important:</strong> These settings store the policy values only. They do not automatically recalculate existing employees’ balances or create their leave balances yet. The next step is to connect these settings to the Add Employee and leave-balance calculation code.
        </div>

        <div class="settings-pending">
            <h3>Other leave types</h3>
            <p>HPL, COM and SCL default entitlements have not been configured because their official amounts were not provided. LWA is recorded as leave without allowance rather than a fixed default entitlement.</p>
        </div>

        <div class="settings-actions">
            <a href="admin.php" class="settings-button settings-secondary">Cancel</a>
            <button type="submit" class="settings-button">Save Settings</button>
        </div>
    </form>
</main>
</body>
</html>
