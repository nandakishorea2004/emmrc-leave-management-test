<?php
session_start();
require_once "../db.php";

/* ADMIN ACCESS CHECK */
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}

/* SELECT EMPLOYEE */
$employee_id = trim($_GET["id"] ?? "");
if ($employee_id === "") {
    header("Location: employees.php");
    exit();
}

/* LOAD EMPLOYEE INCLUDING GENDER AND PROFILE PICTURE */
$stmt = $conn->prepare("
    SELECT
        id,
        employee_id,
        name,
        designation,
        department,
        gender,
        joining_date,
        status,
        profile_picture
    FROM employees
    WHERE employee_id = ?
    LIMIT 1
");
$stmt->bind_param("s", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();
$stmt->close();

if (!$employee) {
    header("Location: employees.php");
    exit();
}

$employee_db_id = (int)$employee["id"];

/* FORMAT EMPLOYEE DETAILS */
$employee["joining_date"] = date("d-m-Y", strtotime($employee["joining_date"]));
$employee["status"] = ucfirst(strtolower($employee["status"]));
$initial = strtoupper(substr(trim($employee["name"]) !== "" ? trim($employee["name"]) : "?", 0, 1));

/* VALIDATE PROFILE PICTURE PATH */
$picture = trim($employee["profile_picture"] ?? "");
$valid_picture = (
    $picture !== "" &&
    strpos($picture, "uploads/employee_photos/") === 0 &&
    strpos($picture, "..") === false &&
    strpos($picture, "\\") === false
);

/* CURRENT YEAR */
$current_year = (int)date("Y");

/* DEFAULT LEAVE BALANCES */
$cl_balance = 0;
$el_balance = 0;
$hpl_balance = 0;

/* GET CL / EL / HPL BALANCES */
$stmt = $conn->prepare("
    SELECT lt.code, lb.closing_balance
    FROM leave_balances lb
    INNER JOIN leave_types lt ON lb.leave_type_id = lt.id
    WHERE lb.employee_id = ?
      AND lb.year = ?
      AND lt.code IN ('CL', 'EL', 'HPL')
");
$stmt->bind_param("ii", $employee_db_id, $current_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $balance = (float)$row["closing_balance"];
    switch ($row["code"]) {
        case "CL": $cl_balance = $balance; break;
        case "EL": $el_balance = $balance; break;
        case "HPL": $hpl_balance = $balance; break;
    }
}
$stmt->close();

/* COM / SCL / LWA TAKEN DAYS */
$com_taken = 0;
$scl_taken = 0;
$lwa_taken = 0;
$stmt = $conn->prepare("
    SELECT lt.code, COALESCE(SUM(lr.days_taken), 0) AS total_days
    FROM leave_records lr
    INNER JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.employee_id = ?
      AND YEAR(lr.from_date) = ?
      AND lt.code IN ('COM', 'SCL', 'LWA')
    GROUP BY lt.code
");
$stmt->bind_param("ii", $employee_db_id, $current_year);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $days = (float)$row["total_days"];
    switch ($row["code"]) {
        case "COM": $com_taken = $days; break;
        case "SCL": $scl_taken = $days; break;
        case "LWA": $lwa_taken = $days; break;
    }
}
$stmt->close();

/* FORMAT DAYS FOR DISPLAY */
function displayDays($value) {
    $value = (float)$value;
    if ($value == floor($value)) {
        return (string)(int)$value;
    }
    return number_format($value, 2, ".", "");
}

/* RECENT LEAVE HISTORY */
$recent_leaves = [];
$stmt = $conn->prepare("
    SELECT lt.code, lt.name AS leave_type, lr.from_date, lr.to_date, lr.days_taken
    FROM leave_records lr
    INNER JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.employee_id = ?
    ORDER BY lr.from_date DESC, lr.id DESC
    LIMIT 10
");
$stmt->bind_param("i", $employee_db_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_leaves[] = [
        "code" => $row["code"],
        "type" => $row["leave_type"],
        "from" => date("d-m-Y", strtotime($row["from_date"])),
        "to" => date("d-m-Y", strtotime($row["to_date"])),
        "days" => $row["days_taken"]
    ];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Profile | EMMRC Leave Management</title>
    <link rel="stylesheet" href="../css/style.css?v=3">
    <style>
        .employee-profile-avatar {
            width: 100px;
            height: 100px;
            min-width: 100px;
            border-radius: 50%;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #e8eefb;
            color: #315eea;
            font-size: 32px;
            font-weight: 700;
        }
        .employee-profile-avatar img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            border-radius: 50%;
        }
        .employee-profile-avatar-fallback {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: #e8eefb;
            color: #315eea;
            font-size: 32px;
            font-weight: 700;
        }
        /* Keep employee information grid clean and remove the grey empty cell. */
        .employee-details-grid {
            background: #ffffff;
        }

        .employee-details-grid .employee-detail-item:last-child {
            grid-column: 1 / -1;
        }

        @media (max-width: 600px) {
            .employee-profile-avatar {
                width: 76px;
                height: 76px;
                min-width: 76px;
                font-size: 26px;
            }
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

<main class="admin-container">
    <div class="employee-profile-back">
        <a href="employees.php">← Back to Employees</a>
    </div>

    <section class="employee-profile-header">
        <div class="employee-profile-main">
            <div class="employee-profile-avatar">
                <?php if ($valid_picture): ?>
                    <img
                        src="../<?php echo htmlspecialchars($picture, ENT_QUOTES, "UTF-8"); ?>"
                        alt="<?php echo htmlspecialchars($employee["name"], ENT_QUOTES, "UTF-8"); ?> profile photo"
                        onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >
                    <div class="employee-profile-avatar-fallback" style="display:none;">
                        <?php echo htmlspecialchars($initial); ?>
                    </div>
                <?php else: ?>
                    <div class="employee-profile-avatar-fallback">
                        <?php echo htmlspecialchars($initial); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <h1><?php echo htmlspecialchars($employee["name"]); ?></h1>
                <p>
                    <?php echo htmlspecialchars($employee["designation"]); ?>
                    ·
                    <?php echo htmlspecialchars($employee["department"]); ?>
                </p>
                <span class="employee-profile-status">
                    ● <?php echo htmlspecialchars($employee["status"]); ?>
                </span>
            </div>
        </div>
    </section>

    <!-- EMPLOYEE INFORMATION -->
    <section class="employee-profile-card">
        <div class="employee-profile-section-title">
            <div>
                <h2>Employee Information</h2>
                <p>Basic employee and service details.</p>
            </div>
        </div>

        <div class="employee-details-grid">
            <div class="employee-detail-item">
                <span>Employee ID</span>
                <strong><?php echo htmlspecialchars($employee["employee_id"]); ?></strong>
            </div>
            <div class="employee-detail-item">
                <span>Employee Name</span>
                <strong><?php echo htmlspecialchars($employee["name"]); ?></strong>
            </div>
            <div class="employee-detail-item">
                <span>Designation</span>
                <strong><?php echo htmlspecialchars($employee["designation"]); ?></strong>
            </div>
            <div class="employee-detail-item">
                <span>Department</span>
                <strong><?php echo htmlspecialchars($employee["department"]); ?></strong>
            </div>
            <div class="employee-detail-item">
                <span>Gender</span>
                <strong><?php echo htmlspecialchars($employee["gender"] ?? "Not specified"); ?></strong>
            </div>
            <div class="employee-detail-item">
                <span>Date of Joining</span>
                <strong><?php echo htmlspecialchars($employee["joining_date"]); ?></strong>
            </div>
            <div class="employee-detail-item">
                <span>Employee Status</span>
                <strong><?php echo htmlspecialchars($employee["status"]); ?></strong>
            </div>
        </div>

    </section>

    <!-- LEAVE BALANCE -->
    <section class="employee-profile-card">
        <div class="employee-profile-section-title">
            <div>
                <h2>Leave Balance</h2>
                <p>Current leave account summary.</p>
            </div>
        </div>
        <div class="profile-leave-grid">
            <div class="profile-leave-card casual">
                <span>Casual Leave</span>
                <strong><?php echo displayDays($cl_balance); ?></strong>
                <small>Remaining</small>
            </div>
            <div class="profile-leave-card earned">
                <span>Earned Leave</span>
                <strong><?php echo displayDays($el_balance); ?></strong>
                <small>Remaining</small>
            </div>
            <div class="profile-leave-card halfpay">
                <span>Half Pay Leave</span>
                <strong><?php echo displayDays($hpl_balance); ?></strong>
                <small>Remaining</small>
            </div>
            <div class="profile-leave-card lwa">
                <span>Leave Without Allowance</span>
                <strong><?php echo displayDays($lwa_taken); ?></strong>
                <small>Taken</small>
            </div>
            <div class="profile-leave-card commuted">
                <span>Commuted Leave</span>
                <strong><?php echo displayDays($com_taken); ?></strong>
                <small>Taken</small>
            </div>
            <div class="profile-leave-card special">
                <span>Special Casual Leave</span>
                <strong><?php echo displayDays($scl_taken); ?></strong>
                <small>Taken</small>
            </div>
        </div>
    </section>

    <!-- EMPLOYEE ACTIONS -->
    <section class="employee-profile-card">
        <div class="employee-profile-section-title">
            <div>
                <h2>Employee Actions</h2>
                <p>Manage this employee's leave information.</p>
            </div>
        </div>
        <div class="profile-action-grid">
            <a href="enter_leave.php?id=<?php echo urlencode($employee_id); ?>&from=profile" class="profile-action">
                <span>📅</span>
                <div><strong>Enter Leave</strong><small>Record leave for employee</small></div>
                <b>›</b>
            </a>
            <a href="../leave_history.php?id=<?php echo urlencode($employee_id); ?>&from=profile" class="profile-action">
                <span>📋</span>
                <div><strong>Leave History</strong><small>View previous leave records</small></div>
                <b>›</b>
            </a>
            <a href="../leave_calendar.php?id=<?php echo urlencode($employee_id); ?>&from=profile" class="profile-action">
                <span>🗓</span>
                <div><strong>Leave Calendar</strong><small>View employee leave calendar</small></div>
                <b>›</b>
            </a>
            <a href="../el_surrender.php?id=<?php echo urlencode($employee_id); ?>&from=profile" class="profile-action">
                <span>↻</span>
                <div><strong>EL Surrender</strong><small>View surrender information</small></div>
                <b>›</b>
            </a>
        </div>
    </section>

    <!-- RECENT LEAVE HISTORY -->
    <section class="employee-profile-card">
        <div class="employee-profile-section-title">
            <div>
                <h2>Recent Leave History</h2>
                <p>Recently entered leave records.</p>
            </div>
        </div>
        <div class="profile-table-wrapper">
            <table class="profile-table">
                <thead>
                    <tr>
                        <th>Leave Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recent_leaves)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding:25px; color:#7b8799;">
                            No leave records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_leaves as $leave): ?>
                        <tr>
                            <td>
                                <span class="profile-leave-badge <?php echo htmlspecialchars(strtolower($leave["code"])); ?>">
                                    <?php echo htmlspecialchars($leave["type"]); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($leave["from"]); ?></td>
                            <td><?php echo htmlspecialchars($leave["to"]); ?></td>
                            <td><?php echo displayDays($leave["days"]); ?></td>
                            <td><span style="color:#26855b; font-weight:600;">Recorded</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<!-- SAVE & RESTORE PROFILE SCROLL POSITION -->
<script>
window.addEventListener("beforeunload", function () {
    sessionStorage.setItem("employee_profile_scroll_position", window.scrollY);
});
window.addEventListener("load", function () {
    const savedPosition = sessionStorage.getItem("employee_profile_scroll_position");
    if (savedPosition !== null) {
        window.scrollTo(0, parseInt(savedPosition, 10));
    }
});
</script>
</body>
</html>
