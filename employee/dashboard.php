<?php

session_start();

require_once "../db.php";

/* =========================================================
   EMPLOYEE ACCESS CHECK
========================================================= */

if (
    !isset($_SESSION["employee_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "employee"
) {
    header("Location: ../index.php");
    exit();
}


/* =========================================================
   GET LOGGED-IN EMPLOYEE
========================================================= */

$employee_id = $_SESSION["employee_id"];


/* =========================================================
   LOAD EMPLOYEE DETAILS
========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        employee_id,
        name,
        designation,
        department,
        joining_date,
        gender,
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
    session_unset();
    session_destroy();

    header("Location: ../index.php");
    exit();
}


/* =========================================================
   EMPLOYEE DETAILS
========================================================= */

$employee_db_id = (int)$employee["id"];

$employee_id = $employee["employee_id"];
$employee_name = $employee["name"];
$employee_full_name = $employee["name"];
$designation = $employee["designation"];
$department = $employee["department"];
$gender = strtolower(trim($employee["gender"] ?? ""));
$profile_picture = trim($employee["profile_picture"] ?? "");

$date_of_joining = date(
    "d-m-Y",
    strtotime($employee["joining_date"])
);


/* =========================================================
   LEAVE BALANCES
========================================================= */

$cl_balance = 0;
$el_balance = 0;
$hpl_balance = 0;


/* ---------------- CL ---------------- */

$stmt = $conn->prepare("
    SELECT lb.closing_balance
    FROM leave_balances lb
    INNER JOIN leave_types lt
        ON lb.leave_type_id = lt.id
    WHERE lb.employee_id = ?
      AND lt.code = 'CL'
      AND lb.year = YEAR(CURDATE())
    LIMIT 1
");

$stmt->bind_param("i", $employee_db_id);
$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    $cl_balance = (float)$row["closing_balance"];
}

$stmt->close();


/* ---------------- EL ---------------- */

$stmt = $conn->prepare("
    SELECT lb.closing_balance
    FROM leave_balances lb
    INNER JOIN leave_types lt
        ON lb.leave_type_id = lt.id
    WHERE lb.employee_id = ?
      AND lt.code = 'EL'
      AND lb.year = YEAR(CURDATE())
    LIMIT 1
");

$stmt->bind_param("i", $employee_db_id);
$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    $el_balance = (float)$row["closing_balance"];
}

$stmt->close();


/* ---------------- HPL ---------------- */

$stmt = $conn->prepare("
    SELECT lb.closing_balance
    FROM leave_balances lb
    INNER JOIN leave_types lt
        ON lb.leave_type_id = lt.id
    WHERE lb.employee_id = ?
      AND lt.code = 'HPL'
      AND lb.year = YEAR(CURDATE())
    LIMIT 1
");

$stmt->bind_param("i", $employee_db_id);
$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    $hpl_balance = (float)$row["closing_balance"];
}

$stmt->close();


/* =========================================================
   LWA TAKEN
========================================================= */

$lwa_taken = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(lr.days_taken), 0) AS total
    FROM leave_records lr
    INNER JOIN leave_types lt
        ON lr.leave_type_id = lt.id
    WHERE lr.employee_id = ?
      AND lt.code = 'LWA'
");

$stmt->bind_param("i", $employee_db_id);
$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    $lwa_taken = (float)$row["total"];
}

$stmt->close();


/* =========================================================
   COM TAKEN
========================================================= */

$com_taken = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(lr.days_taken), 0) AS total
    FROM leave_records lr
    INNER JOIN leave_types lt
        ON lr.leave_type_id = lt.id
    WHERE lr.employee_id = ?
      AND lt.code = 'COM'
");

$stmt->bind_param("i", $employee_db_id);
$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    $com_taken = (float)$row["total"];
}

$stmt->close();


/* =========================================================
   SCL TAKEN
========================================================= */

$scl_taken = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(lr.days_taken), 0) AS total
    FROM leave_records lr
    INNER JOIN leave_types lt
        ON lr.leave_type_id = lt.id
    WHERE lr.employee_id = ?
      AND lt.code = 'SCL'
");

$stmt->bind_param("i", $employee_db_id);
$stmt->execute();

$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    $scl_taken = (float)$row["total"];
}

$stmt->close();


/* =========================================================
   MATERNITY / PATERNITY LEAVE TAKEN
========================================================= */

$ml_taken = 0;
$pl_taken = 0;

$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN lt.code = 'ML' THEN lr.days_taken ELSE 0 END), 0) AS ml_total,
        COALESCE(SUM(CASE WHEN lt.code = 'PL' THEN lr.days_taken ELSE 0 END), 0) AS pl_total
    FROM leave_records lr
    INNER JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.employee_id = ? AND lt.code IN ('ML', 'PL')
");
$stmt->bind_param("i", $employee_db_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if ($row) {
    $ml_taken = (float)$row["ml_total"];
    $pl_taken = (float)$row["pl_total"];
}
$stmt->close();


/* =========================================================
   LOAD EMPLOYEE LEAVE RECORDS
   ONLY FOR THIS EMPLOYEE

   Each leave record is expanded day-by-day.

   Example:
   27-09-2026 to 29-09-2026

   Calendar:
   27
   28
   29
========================================================= */

$leave_calendar = [];

$stmt = $conn->prepare("
    SELECT
        lr.id,
        lr.from_date,
        lr.to_date,
        lr.days_taken,
        lr.session_type,
        lr.reason,
        lr.remarks,
        lt.code,
        lt.name
    FROM leave_records lr
    INNER JOIN leave_types lt
        ON lr.leave_type_id = lt.id
    WHERE lr.employee_id = ?
    ORDER BY lr.from_date ASC, lr.id ASC
");

$stmt->bind_param("i", $employee_db_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $from_date = new DateTime($row["from_date"]);
    $to_date = new DateTime($row["to_date"]);

    /* Include ending date */
    $to_date_inclusive = clone $to_date;
    $to_date_inclusive->modify("+1 day");

    $period = new DatePeriod(
        $from_date,
        new DateInterval("P1D"),
        $to_date_inclusive
    );


    foreach ($period as $date) {

        $date_key = $date->format("Y-m-d");


        /* Leave type → CSS class */

        $leave_class = "casual";

        switch (strtoupper($row["code"])) {

            case "CL":
                $leave_class = "casual";
                break;

            case "EL":
                $leave_class = "earned";
                break;

            case "HPL":
                $leave_class = "half-pay";
                break;

            case "COM":
                $leave_class = "commuted";
                break;

            case "SCL":
                $leave_class = "special";
                break;

            case "LWA":
                $leave_class = "lwa";
                break;

            case "ML":
                $leave_class = "maternity";
                break;

            case "PL":
                $leave_class = "paternity";
                break;

            default:
                $leave_class = "casual";
                break;
        }


        if (!isset($leave_calendar[$date_key])) {
            $leave_calendar[$date_key] = [];
        }


        $leave_calendar[$date_key][] = [

            "code" => $row["code"],

            "name" => $row["name"],

            "class_name" => $leave_class,

            "session_type" => $row["session_type"],

            "reason" => $row["reason"] ?? "",

            "remarks" => $row["remarks"] ?? ""

        ];
    }
}

$stmt->close();


/* =========================================================
   LOAD HOLIDAYS FROM DATABASE

   Includes:
   1. Imported holidays
   2. Holidays manually added by admin
========================================================= */

$holiday_calendar = [];

$holiday_query = "
    SELECT
        id,
        holiday_date,
        day,
        description,
        source_type
    FROM holidays
    ORDER BY holiday_date ASC, id ASC
";

$holiday_result = $conn->query($holiday_query);

if ($holiday_result) {

    while ($holiday = $holiday_result->fetch_assoc()) {

        $date_key = $holiday["holiday_date"];

        $holiday_calendar[$date_key] = [

            "description" => $holiday["description"],

            "day" => $holiday["day"],

            "source_type" => $holiday["source_type"]

        ];
    }
}

/* =========================================================
   CONVERT DATABASE DATA TO JSON
========================================================= */

$leave_json = json_encode(
    $leave_calendar,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);

$holiday_json = json_encode(
    $holiday_calendar,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);


/* =========================================================
   EL SURRENDER HISTORY

   Latest request appears first.
========================================================= */

$el_surrender_history = [];

$stmt = $conn->prepare("
    SELECT
        surrender_date,
        year,
        days,
        status,
        remarks
    FROM el_surrender
    WHERE employee_id = ?
    ORDER BY surrender_date DESC, id DESC
    LIMIT 5
");

$stmt->bind_param("i", $employee_db_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $el_surrender_history[] = $row;
}

$stmt->close();


/* =========================================================
   HTML SAFE VALUES
========================================================= */

$employee_name_js = json_encode(
    $employee_full_name,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);

$employee_id_js = json_encode(
    $employee_id,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);

$designation_js = json_encode(
    $designation,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);

$department_js = json_encode(
    $department,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);

$date_joining_js = json_encode(
    $date_of_joining,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Employee Dashboard | Leave Management
    </title>

    <link
        rel="stylesheet"
        href="../css/style.css?v=2"
    >


    <style>

        /* =================================================
           CALENDAR
        ================================================= */

        .dashboard-calendar-card .weekdays,
        .dashboard-calendar-card .calendar-grid {

            display: grid;

            grid-template-columns:
                repeat(7, 1fr);
        }


        .dashboard-calendar-card .weekday {

            text-align: center;

            padding: 10px 5px;

            color: #7b8799;

            font-size: 12px;

            font-weight: 700;
        }


        .dashboard-calendar-card .calendar-day {

            min-height: 75px;

            border: 1px solid #edf0f5;

            padding: 8px;

            position: relative;

            background: #ffffff;

            box-sizing: border-box;
        }


        .dashboard-calendar-card .calendar-day.empty {

            background: #fafbfc;
        }


        .dashboard-calendar-card .date-number {

            display: inline-flex;

            width: 28px;

            height: 28px;

            align-items: center;

            justify-content: center;

            border-radius: 50%;

            font-size: 12px;

            color: #526078;
        }


        .dashboard-calendar-card
        .calendar-day.today
        .date-number {

            background: #526ee8;

            color: #ffffff;

            font-weight: 700;
        }


        .dashboard-calendar-card .leave-label {

            display: block;

            margin-top: 5px;

            padding: 4px 5px;

            border-radius: 5px;

            font-size: 9px;

            font-weight: 700;

            color: #ffffff;

            text-align: center;

            line-height: 1.25;
        }


        .dashboard-calendar-card .casual {

            background: #0a4092;
        }


        .dashboard-calendar-card .earned {

            background: #40d282;
        }


        .dashboard-calendar-card .half-pay {

            background: #d29a32;
        }


        .dashboard-calendar-card .commuted {

            background: #35a879;
        }


        .dashboard-calendar-card .special {

            background: #0ee4cb;
        }


        .dashboard-calendar-card .lwa {

            background: #dc2020;
        }


        /* Maternity / paternity leave colors */
        .dashboard-calendar-card .maternity { background: #8b5cf6; }
        .dashboard-calendar-card .paternity { background: #526ee8; }

        .maternity-card { border-left: 4px solid #8b5cf6; }
        .maternity-card .leave-icon { background: #f1eaff; color: #7c3aed; }
        .maternity-card .leave-card-content strong { color: #7c3aed; }

        .paternity-card { border-left: 4px solid #526ee8; }
        .paternity-card .leave-icon { background: #e8edff; color: #4059cf; }
        .paternity-card .leave-card-content strong { color: #4059cf; }

        /* Profile photo and initial fallback */
        .profile-photo-avatar {
            position: relative;
            overflow: hidden;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .profile-photo-avatar img {
            width: 100%; height: 100%; display: block;
            object-fit: cover; object-position: center; border-radius: 50%;
        }
        .profile-photo-avatar .avatar-initial {
            width: 100%; height: 100%; display: flex;
            align-items: center; justify-content: center;
            border-radius: 50%; background: #e8edff;
            color: #4059cf; font-weight: 700;
        }

        /* =================================================
           HOLIDAY
        ================================================= */

        .dashboard-calendar-card .holiday {

            display: block;

            margin-top: 5px;

            padding: 4px 5px;

            border-radius: 5px;

            background: #edf0f5;

            color: #68758a;

            font-size: 9px;

            font-weight: 600;

            text-align: center;

            line-height: 1.25;
        }


        /* =================================================
           CALENDAR LEGEND
        ================================================= */

        .dashboard-calendar-card
        .calendar-working-legend {

            display: flex;

            flex-wrap: wrap;

            gap: 15px;

            margin-top: 20px;

            padding-top: 18px;

            border-top: 1px solid #edf0f5;
        }


        .dashboard-calendar-card
        .working-legend-item {

            display: flex;

            align-items: center;

            gap: 7px;

            color: #66748b;

            font-size: 11px;
        }


        .dashboard-calendar-card
        .working-legend-color {

            width: 12px;

            height: 12px;

            border-radius: 3px;
        }


        .dashboard-calendar-card
        .legend-holiday-color {

            background: #edf0f5;
        }


        /* =================================================
           EL SURRENDER HISTORY
        ================================================= */

        .el-surrender-history {

            margin-top: 18px;

            padding-top: 18px;

            border-top: 1px solid #edf0f5;
        }


        .el-surrender-history h3 {

            margin: 0 0 12px;

            color: #263b60;

            font-size: 14px;
        }


        .surrender-history-item {

            display: flex;

            justify-content: space-between;

            gap: 10px;

            padding: 9px 0;

            border-bottom: 1px solid #f0f2f6;

            font-size: 11px;
        }


        .surrender-history-main strong {

            display: block;

            color: #263b60;

            margin-bottom: 3px;
        }


        .surrender-history-main span {

            color: #7b8799;
        }


        .surrender-status {

            align-self: flex-start;

            padding: 4px 7px;

            border-radius: 12px;

            font-size: 9px;

            font-weight: 700;
        }


        .surrender-status.approved {

            background: #dff5e9;

            color: #219563;
        }


        .surrender-status.pending {

            background: #fff0d5;

            color: #c47b10;
        }


        .surrender-status.rejected {

            background: #ffe2e2;

            color: #d33a3a;
        }


        .no-surrender-history {

            color: #9aa4b5;

            font-size: 11px;

            padding: 4px 0;
        }


        @media (max-width: 800px) {

            .dashboard-calendar-card .calendar-day {

                min-height: 65px;
            }
        }


        @media (max-width: 550px) {

            .dashboard-calendar-card .calendar-day {

                min-height: 55px;

                padding: 4px;
            }


            .dashboard-calendar-card .leave-label,
            .dashboard-calendar-card .holiday {

                font-size: 7px;
            }
        }



        /* Single-row, touch-friendly horizontal leave balance strip */
        .leave-cards {
            display: flex !important;
            flex-wrap: nowrap !important;
            gap: 14px;
            overflow-x: auto;
            overflow-y: hidden;
            padding: 4px 2px 14px;
            scroll-snap-type: x proximity;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: #b8c5dc #f1f4f9;
        }
        .leave-cards > .leave-card {
            flex: 0 0 220px !important;
            min-width: 220px;
            max-width: 220px;
            box-sizing: border-box;
            scroll-snap-align: start;
        }
        .leave-cards::-webkit-scrollbar { height: 8px; }
        .leave-cards::-webkit-scrollbar-track { background: #f1f4f9; border-radius: 8px; }
        .leave-cards::-webkit-scrollbar-thumb { background: #b8c5dc; border-radius: 8px; }
        .leave-cards::-webkit-scrollbar-thumb:hover { background: #8fa2c2; }
        @media (max-width: 600px) {
            .leave-cards { gap: 10px; }
            .leave-cards > .leave-card {
                flex-basis: 190px !important;
                min-width: 190px;
                max-width: 190px;
            }
        }

        /* Maternity and Paternity Leave Legend */
        .maternity-dot {
           background: #8b5cf6;
        }

        .paternity-dot {
            background: #526ee8;
        }

    </style>


    <link rel="stylesheet" href="../css/responsive.css?v=1">
</head>


<body>


<div class="dashboard-page">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <header class="dashboard-header">

        <div class="brand-section">

            <img
                src="../assets/emmrc logo.png"
                class="dashboard-logo"
                alt="EMMRC Logo"
            >

            <div class="brand-divider"></div>

            <div class="brand-text">

                <strong>
                    Leave Management
                </strong>

                <span>
                    Employee Portal
                </span>

            </div>

        </div>


        <div class="header-user">

            <div class="user-avatar profile-photo-avatar">
                <?php if ($profile_picture !== ""): ?>
                    <img src="../<?php echo htmlspecialchars(ltrim($profile_picture, "/"), ENT_QUOTES, "UTF-8"); ?>" alt="Employee profile picture" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <span class="avatar-initial" style="display:none;"><?php echo htmlspecialchars(strtoupper(substr($employee_name, 0, 1)), ENT_QUOTES, "UTF-8"); ?></span>
                <?php else: ?>
                    <span class="avatar-initial"><?php echo htmlspecialchars(strtoupper(substr($employee_name, 0, 1)), ENT_QUOTES, "UTF-8"); ?></span>
                <?php endif; ?>
            </div>


            <div class="header-user-details">

                <strong>

                    <?php
                    echo htmlspecialchars($employee_name);
                    ?>

                </strong>

                <span>

                    <?php
                    echo htmlspecialchars($designation);
                    ?>

                </span>

            </div>


            <a
                href="../login.php?action=logout"
                class="logout-button"
            >

                <span class="logout-icon">
                    ↪
                </span>

                Logout

            </a>

        </div>

    </header>



    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="dashboard-container">


        <!-- =================================================
             WELCOME
        ================================================== -->

        <section class="top-section">


            <div class="welcome-area">

                <h1>

                    Welcome,

                    <?php
                    echo htmlspecialchars($employee_name);
                    ?>

                </h1>

                <p>
                    Here is an overview of your leave information.
                </p>

            </div>


            <!-- EMPLOYEE INFORMATION -->

            <div class="employee-info-card">


                <div class="profile-avatar profile-photo-avatar">
                    <?php if ($profile_picture !== ""): ?>
                        <img src="../<?php echo htmlspecialchars(ltrim($profile_picture, "/"), ENT_QUOTES, "UTF-8"); ?>" alt="Employee profile picture" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <span class="avatar-initial" style="display:none;"><?php echo htmlspecialchars(strtoupper(substr($employee_name, 0, 1)), ENT_QUOTES, "UTF-8"); ?></span>
                    <?php else: ?>
                        <span class="avatar-initial"><?php echo htmlspecialchars(strtoupper(substr($employee_name, 0, 1)), ENT_QUOTES, "UTF-8"); ?></span>
                    <?php endif; ?>
                </div>


                <div class="employee-detail">

                    <span>
                        Name
                    </span>

                    <strong>

                        <?php
                        echo htmlspecialchars(
                            $employee_full_name
                        );
                        ?>

                    </strong>

                </div>


                <div class="employee-detail">

                    <span>
                        Employee ID
                    </span>

                    <strong>

                        <?php
                        echo htmlspecialchars(
                            $employee_id
                        );
                        ?>

                    </strong>

                </div>


                <div class="employee-detail">

                    <span>
                        Designation
                    </span>

                    <strong>

                        <?php
                        echo htmlspecialchars(
                            $designation
                        );
                        ?>

                    </strong>

                </div>


                <div class="employee-detail">

                    <span>
                        Department
                    </span>

                    <strong>

                        <?php
                        echo htmlspecialchars(
                            $department
                        );
                        ?>

                    </strong>

                </div>


                <div class="employee-detail last-detail">

                    <span>
                        Date of Joining
                    </span>

                    <strong>

                        <?php
                        echo htmlspecialchars(
                            $date_of_joining
                        );
                        ?>

                    </strong>

                </div>


            </div>

        </section>



        <!-- =================================================
             LEAVE BALANCE
        ================================================== -->

        <section class="leave-section">


            <div class="section-title">

                <h2>
                    Leave Balance
                </h2>

                <p>
                    Your available leave balance
                </p>

            </div>


            <div class="leave-cards">


                <!-- CL -->

                <div class="leave-card casual-card">

                    <div class="leave-icon">
                        ◫
                    </div>

                    <div class="leave-card-content">

                        <span>
                            Casual Leave
                        </span>

                        <strong>

                            <?php
                            echo $cl_balance;
                            ?>

                        </strong>

                        <small>

                            <?php
                            echo $cl_balance;
                            ?>

                            remaining out of 20

                        </small>

                    </div>

                </div>



                <!-- EL -->

                <div class="leave-card earned-card">

                    <div class="leave-icon">
                        ◉
                    </div>

                    <div class="leave-card-content">

                        <span>
                            Earned Leave
                        </span>

                        <strong>

                            <?php
                            echo $el_balance;
                            ?>

                        </strong>

                        <small>

                            <?php
                            echo $el_balance;
                            ?>

                            days available

                        </small>

                    </div>

                </div>



                <!-- HPL -->

                <div class="leave-card halfpay-card">

                    <div class="leave-icon">
                        ◷
                    </div>

                    <div class="leave-card-content">

                        <span>
                            Half Pay Leave
                        </span>

                        <strong>

                            <?php
                            echo $hpl_balance;
                            ?>

                        </strong>

                        <small>

                            <?php
                            echo $hpl_balance;
                            ?>

                            days available

                        </small>

                    </div>

                </div>



                <!-- LWA -->

                <div class="leave-card lwa-card">

                    <div class="leave-icon">
                        ▤
                    </div>

                    <div class="leave-card-content">

                        <span>
                            Leave Without Allow
                        </span>

                        <strong>

                            <?php
                            echo $lwa_taken;
                            ?>

                        </strong>

                        <small>

                            <?php
                            echo $lwa_taken;
                            ?>

                            days taken

                        </small>

                    </div>

                </div>



                <!-- COM -->

                <div class="leave-card commuted-card">

                    <div class="leave-icon">
                        ▣
                    </div>

                    <div class="leave-card-content">

                        <span>
                            Commuted Leave
                        </span>

                        <strong>

                            <?php
                            echo $com_taken;
                            ?>

                        </strong>

                        <small>

                            <?php
                            echo $com_taken;
                            ?>

                            days taken

                        </small>

                    </div>

                </div>



                <!-- SCL -->

                <div class="leave-card special-card">

                    <div class="leave-icon">
                        ☆
                    </div>

                    <div class="leave-card-content">

                        <span>
                            Spl. Casual Leave
                        </span>

                        <strong>

                            <?php
                            echo $scl_taken;
                            ?>

                        </strong>

                        <small>

                            <?php
                            echo $scl_taken;
                            ?>

                            days taken

                        </small>

                    </div>

                </div>

                <?php if ($gender === "female"): ?>
                    <div class="leave-card maternity-card">
                        <div class="leave-icon">♡</div>
                        <div class="leave-card-content">
                            <span>Maternity Leave Taken</span>
                            <strong><?php echo htmlspecialchars((string)$ml_taken, ENT_QUOTES, "UTF-8"); ?></strong>
                            <small><?php echo htmlspecialchars((string)$ml_taken, ENT_QUOTES, "UTF-8"); ?> days taken</small>
                        </div>
                    </div>
                <?php elseif ($gender === "male"): ?>
                    <div class="leave-card paternity-card">
                        <div class="leave-icon">♡</div>
                        <div class="leave-card-content">
                            <span>Paternity Leave Taken</span>
                            <strong><?php echo htmlspecialchars((string)$pl_taken, ENT_QUOTES, "UTF-8"); ?></strong>
                            <small><?php echo htmlspecialchars((string)$pl_taken, ENT_QUOTES, "UTF-8"); ?> days taken</small>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        </section>



        <!-- =================================================
             CALENDAR + QUICK ACTIONS
        ================================================== -->

        <section class="dashboard-grid">


            <!-- =================================================
                 CALENDAR
            ================================================== -->

            <div class="calendar-card dashboard-calendar-card">


                <div class="calendar-title-row">


                    <div class="calendar-title">

                        <div class="calendar-main-icon">
                            ▣
                        </div>

                        <div>

                            <h2>
                                Leave Calendar
                            </h2>

                            <p>
                                View your previous and upcoming leaves
                            </p>

                        </div>

                    </div>


                    <div class="calendar-navigation">

                        <button
                            type="button"
                            class="calendar-nav-button"
                            onclick="previousMonth()"
                        >
                            ‹
                        </button>

                        <strong id="monthYear"></strong>

                        <button
                            type="button"
                            class="calendar-nav-button"
                            onclick="nextMonth()"
                        >
                            ›
                        </button>

                    </div>

                </div>


                <div class="weekdays">

                    <div class="weekday">
                        Sun
                    </div>

                    <div class="weekday">
                        Mon
                    </div>

                    <div class="weekday">
                        Tue
                    </div>

                    <div class="weekday">
                        Wed
                    </div>

                    <div class="weekday">
                        Thu
                    </div>

                    <div class="weekday">
                        Fri
                    </div>

                    <div class="weekday">
                        Sat
                    </div>

                </div>


                <div
                    id="calendarGrid"
                    class="calendar-grid"
                ></div>


                <div class="calendar-working-legend">


                    <div class="working-legend-item">

                        <span class="working-legend-color casual"></span>

                        Casual Leave

                    </div>


                    <div class="working-legend-item">

                        <span class="working-legend-color earned"></span>

                        Earned Leave

                    </div>


                    <div class="working-legend-item">

                        <span class="working-legend-color half-pay"></span>

                        Half Pay Leave

                    </div>


                    <div class="working-legend-item">

                        <span class="working-legend-color commuted"></span>

                        Commuted Leave

                    </div>


                    <div class="working-legend-item">

                        <span class="working-legend-color special"></span>

                        Special Casual Leave

                    </div>


                    <div class="working-legend-item">

                        <span class="working-legend-color lwa"></span>

                        Leave Without Allowance

                    </div>

                    <?php if ($gender === "female"): ?>
                    <div class="working-legend-item">
                        <span class="working-legend-color maternity"></span>
                        Maternity Leave
                    </div>
                    <?php elseif ($gender === "male"): ?>
                    <div class="working-legend-item">
                        <span class="working-legend-color paternity"></span>
                        Paternity Leave
                    </div>
                    <?php endif; ?>

                    <div class="working-legend-item">

                        <span class="working-legend-color legend-holiday-color"></span>

                        Holiday

                    </div>


                </div>

            </div>



            <!-- =================================================
                 QUICK ACTIONS
            ================================================== -->

            <div class="actions-card">


                <div class="actions-heading">

                    <div class="actions-icon">
                        ⚡
                    </div>

                    <div>

                        <h2>
                            Quick Actions
                        </h2>

                        <p>
                            Manage your leave information
                        </p>

                    </div>

                </div>



                <!-- DOWNLOAD -->

                <button
                    type="button"
                    class="action-button report-action"
                    onclick="downloadReport()"
                >

                    <div class="action-round-icon">
                        ↓
                    </div>

                    <div class="action-text">

                        <strong>
                            Download Report
                        </strong>

                        <span>
                            Download leave statement
                        </span>

                    </div>

                    <div class="action-arrow">
                        ›
                    </div>

                </button>



                <!-- EL SURRENDER -->

                <button
                    type="button"
                    class="action-button surrender-action"
                    onclick="window.location.href='../el_surrender.php?id=<?php echo urlencode($employee_id); ?>&from=employee';"
                >

                    <div class="action-round-icon">
                        ↔
                    </div>

                    <div class="action-text">

                        <strong>
                            Surrender Earned Leave
                        </strong>

                        <span>
                            Apply for EL surrender
                        </span>

                    </div>

                    <div class="action-arrow">
                        ›
                    </div>

                </button>



                <!-- LEAVE HISTORY -->

                <button
                    type="button"
                    class="action-button history-action"
                    onclick="window.location.href='../leave_history.php?id=<?php echo urlencode($employee_id); ?>&from=employee';"
                >

                    <div class="action-round-icon">
                        ≡
                    </div>

                    <div class="action-text">

                        <strong>
                            Leave History
                        </strong>

                        <span>
                            View your leave and EL surrender history
                        </span>

                    </div>

                    <div class="action-arrow">
                        ›
                    </div>

                </button>



                <!-- =================================================
                     EL SURRENDER HISTORY
                ================================================== -->

                <div class="el-surrender-history">

                    <h3>
                        EL Surrender History
                    </h3>


                    <?php if (!empty($el_surrender_history)): ?>


                        <?php foreach ($el_surrender_history as $surrender): ?>

                            <?php

                            $status = strtolower(
                                $surrender["status"] ?? ""
                            );

                            $status_class = in_array(
                                $status,
                                [
                                    "approved",
                                    "pending",
                                    "rejected"
                                ],
                                true
                            )
                                ? $status
                                : "pending";

                            ?>

                            <div class="surrender-history-item">


                                <div class="surrender-history-main">

                                    <strong>

                                        <?php
                                        echo htmlspecialchars(
                                            $surrender["days"]
                                        );
                                        ?>

                                        day<?php
                                        echo (
                                            (float)$surrender["days"] == 1.0
                                                ? ""
                                                : "s"
                                        );
                                        ?>

                                        &nbsp;•&nbsp;

                                        <?php
                                        echo htmlspecialchars(
                                            date(
                                                "d-m-Y",
                                                strtotime(
                                                    $surrender["surrender_date"]
                                                )
                                            )
                                        );
                                        ?>

                                    </strong>


                                    <span>

                                        <?php

                                        $remarks =
                                            trim(
                                                $surrender["remarks"] ?? ""
                                            );

                                        echo htmlspecialchars(
                                            $remarks !== ""
                                                ? $remarks
                                                : "No remarks"
                                        );

                                        ?>

                                    </span>

                                </div>


                                <span
                                    class="surrender-status <?php echo $status_class; ?>"
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        ucfirst(
                                            $status !== ""
                                                ? $status
                                                : "Pending"
                                        )
                                    );
                                    ?>

                                </span>


                            </div>

                        <?php endforeach; ?>


                    <?php else: ?>


                        <div class="no-surrender-history">

                            No EL surrender history found.

                        </div>


                    <?php endif; ?>


                </div>



                <!-- =================================================
                     LEGEND
                ================================================== -->

                <div class="actions-legend">

                    <h3>
                        Leave Calendar Legend
                    </h3>


                    <div class="legend-row">

                        <i class="legend-dot casual-dot"></i>

                        Casual Leave

                    </div>


                    <div class="legend-row">

                        <i class="legend-dot earned-dot"></i>

                        Earned Leave

                    </div>


                    <div class="legend-row">

                        <i class="legend-dot halfpay-dot"></i>

                        Half Pay Leave

                    </div>


                    <div class="legend-row">

                        <i class="legend-dot special-dot"></i>

                        Special Casual Leave

                    </div>

                    <?php if ($gender === "female"): ?>

                       <div class="legend-row">
                          <i class="legend-dot maternity-dot"></i>
                            Maternity Leave
                       </div>

                    <?php elseif ($gender === "male"): ?>

                      <div class="legend-row">
                       <i class="legend-dot paternity-dot"></i>
                            Paternity Leave
                       </div>

                    <?php endif; ?>


                    <div class="legend-row">

                        <i class="legend-dot holiday-dot"></i>

                        Common Holiday

                    </div>


                </div>


            </div>


        </section>


    </main>


</div>



<script>

/* =========================================================
   DATABASE DATA

   IMPORTANT:
   These are now the actual variables created by PHP.

   leaveData = logged-in employee's leave records
   holidayData = holidays from MySQL
========================================================= */

const leaveData =
    <?php echo $leave_json ?: "{}"; ?>;


const holidayData =
    <?php echo $holiday_json ?: "{}"; ?>;


/* =========================================================
   CALENDAR STATE
========================================================= */

let currentDate = new Date();

const today = new Date();


/* =========================================================
   FORMAT DATE
========================================================= */

function formatDate(year, month, day) {

    const monthNumber =
        String(month + 1).padStart(2, "0");

    const dayNumber =
        String(day).padStart(2, "0");

    return `${year}-${monthNumber}-${dayNumber}`;
}


/* =========================================================
   CREATE LEAVE LABEL
========================================================= */

function createLeaveLabel(leave) {

    const leaveElement =
        document.createElement("span");

    leaveElement.className =
        "leave-label " + leave.class_name;


    let label = leave.code;


    switch (leave.code) {

        case "CL":
            label = "CL";
            break;

        case "EL":
            label = "EL";
            break;

        case "HPL":
            label = "HPL";
            break;

        case "COM":
            label = "COM";
            break;

        case "SCL":
            label = "SCL";
            break;

        case "LWA":
            label = "LWA";
            break;

        case "ML":
            label = "ML";
            break;

        case "PL":
            label = "PL";
            break;

        default:
            label = leave.name;
            break;
    }


    leaveElement.textContent = label;


    let tooltip = leave.name;


    if (leave.session_type) {

        tooltip +=
            " | " +
            leave.session_type;
    }


    if (leave.reason) {

        tooltip +=
            " | Reason: " +
            leave.reason;
    }


    leaveElement.title = tooltip;


    return leaveElement;
}


/* =========================================================
   CREATE HOLIDAY LABEL
========================================================= */

function createHolidayLabel(holiday) {

    const holidayElement =
        document.createElement("span");

    holidayElement.className =
        "holiday";


    holidayElement.textContent =
        holiday.description;


    let tooltip =
        holiday.description;


    if (holiday.day) {

        tooltip +=
            " | " +
            holiday.day;
    }


    holidayElement.title =
        tooltip;


    return holidayElement;
}


/* =========================================================
   DEFAULT HOLIDAY

   Every Sunday = Holiday

   Every second Saturday = Holiday

   Second Saturday dates are:
   8, 9, 10, 11, 12, 13 or 14
   depending on which day is Saturday.

   More simply:
   Saturday + date between 8 and 14.
========================================================= */

function getDefaultHoliday(date) {

    const dayOfWeek =
        date.getDay();

    const dayOfMonth =
        date.getDate();


    /* Sunday */

    if (dayOfWeek === 0) {

        return {
            description: "Sunday",
            day: "Sunday",
            source_type: "DEFAULT"
        };
    }


    /* Second Saturday */

    if (
        dayOfWeek === 6 &&
        dayOfMonth >= 8 &&
        dayOfMonth <= 14
    ) {

        return {
            description: "Second Saturday",
            day: "Saturday",
            source_type: "DEFAULT"
        };
    }


    return null;
}


/* =========================================================
   GET HOLIDAY FOR A DATE

   Priority:

   1. Admin/database holiday
   2. Default Sunday
   3. Default second Saturday
========================================================= */

function getHoliday(dateKey, year, month, day) {

    /* Database holiday */

    if (holidayData[dateKey]) {

        return holidayData[dateKey];
    }


    /* Default holiday */

    const date =
        new Date(year, month, day);

    return getDefaultHoliday(date);
}


/* =========================================================
   RENDER CALENDAR
========================================================= */

function renderCalendar() {

    const year =
        currentDate.getFullYear();

    const month =
        currentDate.getMonth();


    const monthNames = [

        "January",
        "February",
        "March",
        "April",
        "May",
        "June",
        "July",
        "August",
        "September",
        "October",
        "November",
        "December"

    ];


    document.getElementById(
        "monthYear"
    ).textContent =
        `${monthNames[month]} ${year}`;


    const calendarGrid =
        document.getElementById(
            "calendarGrid"
        );


    calendarGrid.innerHTML = "";


    const firstDay =
        new Date(
            year,
            month,
            1
        ).getDay();


    const daysInMonth =
        new Date(
            year,
            month + 1,
            0
        ).getDate();


    /* Empty cells before first day */

    for (
        let i = 0;
        i < firstDay;
        i++
    ) {

        const emptyDay =
            document.createElement("div");

        emptyDay.className =
            "calendar-day empty";

        calendarGrid.appendChild(
            emptyDay
        );
    }


    /* =====================================================
       DAYS
    ===================================================== */

    for (
        let day = 1;
        day <= daysInMonth;
        day++
    ) {


        const dateKey =
            formatDate(
                year,
                month,
                day
            );


        const dayElement =
            document.createElement("div");


        dayElement.className =
            "calendar-day";


        /* Date number */

        const dateNumber =
            document.createElement("span");


        dateNumber.className =
            "date-number";


        dateNumber.textContent =
            day;


        dayElement.appendChild(
            dateNumber
        );


        /* =================================================
           TODAY
        ================================================= */

        if (

            year === today.getFullYear() &&

            month === today.getMonth() &&

            day === today.getDate()

        ) {

            dayElement.classList.add(
                "today"
            );
        }


        /* =================================================
           EMPLOYEE LEAVE

           Only the logged-in employee's
           records are present in leaveData.
        ================================================= */

        if (

            leaveData[dateKey] &&

            Array.isArray(
                leaveData[dateKey]
            )

        ) {

            leaveData[dateKey].forEach(
                function(leave) {

                    dayElement.appendChild(
                        createLeaveLabel(
                            leave
                        )
                    );

                }
            );
        }


        /* =================================================
           HOLIDAY

           This includes:
           - holidays from MySQL
           - Sunday
           - second Saturday
        ================================================= */

        const holiday =
            getHoliday(
                dateKey,
                year,
                month,
                day
            );


        if (holiday) {

            dayElement.appendChild(

                createHolidayLabel(
                    holiday
                )

            );
        }


        /* Add day to calendar */

        calendarGrid.appendChild(
            dayElement
        );
    }
}


/* =========================================================
   PREVIOUS MONTH
========================================================= */

function previousMonth() {

    currentDate.setMonth(
        currentDate.getMonth() - 1
    );

    renderCalendar();
}


/* =========================================================
   NEXT MONTH
========================================================= */

function nextMonth() {

    currentDate.setMonth(
        currentDate.getMonth() + 1
    );

    renderCalendar();
}


/* =========================================================
   DOWNLOAD REPORT
========================================================= */

function downloadReport() {

    const report =
`EMPLOYEE LEAVE REPORT

Name:
<?php echo addslashes($employee_full_name); ?>

Employee ID:
<?php echo addslashes($employee_id); ?>

Designation:
<?php echo addslashes($designation); ?>

Department:
<?php echo addslashes($department); ?>

Date of Joining:
<?php echo addslashes($date_of_joining); ?>


LEAVE BALANCE

Casual Leave:
<?php echo $cl_balance; ?>

Earned Leave:
<?php echo $el_balance; ?>

Half Pay Leave:
<?php echo $hpl_balance; ?>

Leave Without Allow:
<?php echo $lwa_taken; ?>

Commuted Leave:
<?php echo $com_taken; ?>

Special Casual Leave:
<?php echo $scl_taken; ?>

<?php if ($gender === "female"): ?>
Maternity Leave Taken:
<?php echo $ml_taken; ?> days
<?php elseif ($gender === "male"): ?>
Paternity Leave Taken:
<?php echo $pl_taken; ?> days
<?php endif; ?>
`;


    const blob =
        new Blob(
            [report],
            {
                type: "text/plain"
            }
        );


    const url =
        URL.createObjectURL(blob);


    const link =
        document.createElement("a");


    link.href = url;


    link.download =
        "employee_leave_report.txt";


    document.body.appendChild(
        link
    );


    link.click();


    link.remove();


    URL.revokeObjectURL(
        url
    );
}


/* =========================================================
   INITIALIZE CALENDAR
========================================================= */

renderCalendar();

</script>


</body>

</html>