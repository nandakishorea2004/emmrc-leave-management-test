<?php

/* =========================================================
   SESSION & ACCESS
   ========================================================= */

session_start();
require_once "db.php";

$role = $_SESSION["role"] ?? "";

/*
 * Admin:
 *   Employee is selected using ?id=123456
 *
 * Employee:
 *   Employee is always taken from the logged-in session.
 */

if ($role === "admin") {

    $employee_id = trim($_GET["id"] ?? "");

    if ($employee_id === "") {
        header("Location: admin/employees.php");
        exit();
    }

} elseif ($role === "employee") {

    if (!isset($_SESSION["employee_id"])) {
        header("Location: index.php");
        exit();
    }

    $employee_id = $_SESSION["employee_id"];

} else {

    header("Location: index.php");
    exit();
}


/* =========================================================
   LOAD EMPLOYEE FROM DATABASE
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        employee_id,
        name,
        designation,
        department,
        joining_date,
        status
    FROM employees
    WHERE employee_id = ?
    LIMIT 1
");

$stmt->bind_param("s", $employee_id);
$stmt->execute();

$result = $stmt->get_result();

$employee = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   EMPLOYEE NOT FOUND
   ========================================================= */

if (!$employee) {

    if ($role === "admin") {
        header("Location: admin/employees.php");
    } else {
        header("Location: employee/dashboard.php");
    }

    exit();
}


/* =========================================================
   DATABASE EMPLOYEE ID
   ========================================================= */

$employee_db_id = (int)$employee["id"];


/* =========================================================
   LOAD EMPLOYEE LEAVE RECORDS
   =========================================================

   Every leave record is expanded day-by-day in PHP.

   Example:

   From: 10-09-2026
   To:   12-09-2026

   Calendar receives:

   10-09-2026
   11-09-2026
   12-09-2026
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
    $to_date   = new DateTime($row["to_date"]);

    /*
     * Include the ending date.
     */
    $to_date_inclusive = clone $to_date;
    $to_date_inclusive->modify("+1 day");

    $period = new DatePeriod(
        $from_date,
        new DateInterval("P1D"),
        $to_date_inclusive
    );


    foreach ($period as $date) {

        $date_key = $date->format("Y-m-d");


        /*
         * Leave type → CSS class
         */

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

            default:
                $leave_class = "casual";
                break;
        }


        /*
         * Store leave information.
         *
         * If multiple records happen to fall on the same
         * date, keep them all.
         */

        if (!isset($leave_calendar[$date_key])) {
            $leave_calendar[$date_key] = [];
        }


        $leave_calendar[$date_key][] = [
            "code"        => $row["code"],
            "name"        => $row["name"],
            "class_name"  => $leave_class,
            "session_type"=> $row["session_type"],
            "reason"      => $row["reason"] ?? "",
            "remarks"     => $row["remarks"] ?? ""
        ];
    }
}

$stmt->close();


/* =========================================================
   LOAD ALL HOLIDAYS
   =========================================================

   This includes:

   1. Holidays imported from CSV
   2. Holidays manually added by admin

   Both are stored in the holidays table.
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
    ORDER BY holiday_date ASC
";

$holiday_result = $conn->query($holiday_query);


if ($holiday_result) {

    while ($holiday = $holiday_result->fetch_assoc()) {

        $date_key = $holiday["holiday_date"];

        $holiday_calendar[$date_key] = [
            "description" => $holiday["description"],
            "day"         => $holiday["day"],
            "source_type" => $holiday["source_type"]
        ];
    }
}


/* =========================================================
   DEFAULT WEEKLY HOLIDAYS
   =========================================================

   Every Sunday:
       Default Holiday

   Every Second Saturday:
       Default Holiday

   These are NOT stored in the holidays table.

   They are generated automatically for the calendar.

   Database holidays have priority if the same date
   already exists in the holidays table.
========================================================= */

$default_start_year = 2000;
$default_end_year   = 2035;


for (
    $year = $default_start_year;
    $year <= $default_end_year;
    $year++
) {

    for (
        $month = 1;
        $month <= 12;
        $month++
    ) {

        $days_in_month = cal_days_in_month(
            CAL_GREGORIAN,
            $month,
            $year
        );


        for (
            $day = 1;
            $day <= $days_in_month;
            $day++
        ) {

            $date_key = sprintf(
                "%04d-%02d-%02d",
                $year,
                $month,
                $day
            );


            $timestamp = strtotime($date_key);


            /* Sunday */

            if (date("w", $timestamp) == 0) {

                /*
                 * Only add if there is no
                 * database holiday on this date.
                 */

                if (!isset($holiday_calendar[$date_key])) {

                    $holiday_calendar[$date_key] = [

                        "description" => "Sunday",

                        "day" => "Sunday",

                        "source_type" => "DEFAULT"

                    ];

                }

            }


            /* Second Saturday */

            if (
                date("w", $timestamp) == 6 &&
                $day >= 8 &&
                $day <= 14
            ) {

                /*
                 * Only add if there is no
                 * database holiday on this date.
                 */

                if (!isset($holiday_calendar[$date_key])) {

                    $holiday_calendar[$date_key] = [

                        "description" => "Second Saturday",

                        "day" => "Saturday",

                        "source_type" => "DEFAULT"

                    ];

                }

            }

        }

    }

}


/* =========================================================
   CONVERT DATA TO JSON FOR JAVASCRIPT
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
   BACK LINK
   ========================================================= */

if ($role === "admin") {

    $back_link =
        "admin/employee_profile.php?id=" .
        urlencode($employee_id);

    $back_text = "← Back to Employee Profile";

} else {

    $back_link = "employee/dashboard.php";
    $back_text = "← Back to Dashboard";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Leave Calendar | EMMRC</title>

    <link
        rel="stylesheet"
        href="css/style.css?v=2"
    >


    <style>

        /* =================================================
           PAGE LAYOUT
           ================================================= */

        body {
            margin: 0;
            background: #f5f7fb;
            font-family: Arial, sans-serif;
        }

        .calendar-page {
            min-height: 100vh;
            height: auto;
            overflow: visible;
        }

        body:has(.calendar-page) {
            overflow-x: hidden;
            overflow-y: auto;
        }

        .calendar-container {
            width: calc(100% - 80px);
            max-width: 1200px;
            margin: 30px auto;
        }


        /* =================================================
           BACK NAVIGATION
           ================================================= */

        .calendar-back {
            display: inline-block;
            margin-bottom: 18px;
            color: #526ee8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .calendar-back:hover {
            text-decoration: underline;
        }


        /* =================================================
           CALENDAR CARD
           ================================================= */

        .calendar-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 5px 20px rgba(30, 50, 90, 0.08);
        }

        .calendar-header {
            margin-bottom: 22px;
        }

        .calendar-title h1 {
            margin: 0;
            color: #173b72;
            font-size: 24px;
        }

        .calendar-title p {
            margin: 6px 0 0;
            color: #7b8799;
            font-size: 13px;
        }


        /* =================================================
           EMPLOYEE INFORMATION
           ================================================= */

        .employee-info {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 25px;
        }

        .info-box {
            background: #f8f9fc;
            border: 1px solid #edf0f6;
            border-radius: 10px;
            padding: 13px;
        }

        .info-box span {
            display: block;
            color: #8993a5;
            font-size: 11px;
            margin-bottom: 5px;
        }

        .info-box strong {
            color: #263b60;
            font-size: 14px;
        }


        /* =================================================
           MONTH NAVIGATION
           ================================================= */

        .month-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .month-controls button {
            border: none;
            background: #eef1ff;
            color: #526ee8;
            width: 38px;
            height: 34px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
        }

        .month-controls button:hover {
            background: #526ee8;
            color: #ffffff;
        }

        .month-controls h2 {
            width: 190px;
            text-align: center;
            margin: 0;
            color: #263b60;
            font-size: 19px;
        }


        /* =================================================
           CALENDAR GRID
           ================================================= */

        .weekdays,
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }

        .weekday {
            text-align: center;
            padding: 10px 5px;
            color: #7b8799;
            font-size: 12px;
            font-weight: 700;
        }

        .calendar-day {
            min-height: 75px;
            border: 1px solid #edf0f5;
            padding: 8px;
            position: relative;
            background: #ffffff;
            box-sizing: border-box;
        }

        .calendar-day.empty {
            background: #fafbfc;
        }

        .date-number {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 12px;
            color: #526078;
        }


        /* =================================================
           TODAY
           ================================================= */

        .calendar-day.today .date-number {
            background: #526ee8;
            color: #ffffff;
            font-weight: 700;
        }


        /* =================================================
           LEAVE LABELS
           ================================================= */

        .leave-label {
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

        .casual {
            background: #0a4092;
        }

        .earned {
            background: #40d282;
        }

        .half-pay {
            background: #d29a32;
        }

        .commuted {
            background: #35a879;
        }

        .special {
            background: #0ee4cb;
        }

        .lwa {
            background: #dc2020;
        }


        /* =================================================
           HOLIDAY LABEL
           ================================================= */

        .holiday {
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
           LEGEND
           ================================================= */

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 25px;
            padding-top: 18px;
            border-top: 1px solid #edf0f5;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 7px;
            color: #66748b;
            font-size: 11px;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
        }

        .legend-holiday {
            background: #edf0f5;
        }


        /* =================================================
           NO RECORD MESSAGE
           ================================================= */

        .no-calendar-data {
            text-align: center;
            color: #9aa4b5;
            font-size: 9px;
            margin-top: 5px;
        }


        /* =================================================
           RESPONSIVE DESIGN
           ================================================= */

        @media (max-width: 800px) {

            .calendar-container {
                width: calc(100% - 30px);
            }

            .employee-info {
                grid-template-columns: repeat(2, 1fr);
            }

            .calendar-day {
                min-height: 65px;
            }

        }


        @media (max-width: 550px) {

            .employee-info {
                grid-template-columns: 1fr;
            }

            .calendar-card {
                padding: 18px;
            }

            .calendar-day {
                min-height: 55px;
                padding: 4px;
            }

            .leave-label,
            .holiday {
                font-size: 7px;
            }

        }

    </style>


    <link rel="stylesheet" href="css/responsive.css?v=1">
</head>


<body>

<div class="calendar-page">

    <div class="calendar-container">


        <!-- =================================================
             BACK NAVIGATION
             ================================================= -->

        <a
            href="<?php echo htmlspecialchars($back_link); ?>"
            class="calendar-back"
        >
            <?php echo htmlspecialchars($back_text); ?>
        </a>


        <!-- =================================================
             MAIN CALENDAR CARD
             ================================================= -->

        <div class="calendar-card">


            <!-- =================================================
                 PAGE HEADING
                 ================================================= -->

            <div class="calendar-header">

                <div class="calendar-title">

                    <h1>Leave Calendar</h1>

                    <p>
                        Leave records and holidays for the employee
                    </p>

                </div>

            </div>


            <!-- =================================================
                 EMPLOYEE INFORMATION
                 ================================================= -->

            <div class="employee-info">


                <div class="info-box">

                    <span>Employee ID</span>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $employee["employee_id"]
                        );
                        ?>
                    </strong>

                </div>


                <div class="info-box">

                    <span>Employee Name</span>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $employee["name"]
                        );
                        ?>
                    </strong>

                </div>


                <div class="info-box">

                    <span>Designation</span>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $employee["designation"]
                        );
                        ?>
                    </strong>

                </div>


                <div class="info-box">

                    <span>Department</span>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $employee["department"]
                        );
                        ?>
                    </strong>

                </div>


            </div>


            <!-- =================================================
                 MONTH NAVIGATION
                 ================================================= -->

            <div class="month-controls">

                <button
                    type="button"
                    onclick="previousMonth()"
                    aria-label="Previous month"
                >
                    ‹
                </button>


                <h2 id="monthYear"></h2>


                <button
                    type="button"
                    onclick="nextMonth()"
                    aria-label="Next month"
                >
                    ›
                </button>

            </div>


            <!-- =================================================
                 WEEKDAY HEADINGS
                 ================================================= -->

            <div class="weekdays">

                <div class="weekday">Sun</div>
                <div class="weekday">Mon</div>
                <div class="weekday">Tue</div>
                <div class="weekday">Wed</div>
                <div class="weekday">Thu</div>
                <div class="weekday">Fri</div>
                <div class="weekday">Sat</div>

            </div>


            <!-- =================================================
                 CALENDAR GRID
                 ================================================= -->

            <div
                id="calendarGrid"
                class="calendar-grid"
            ></div>


            <!-- =================================================
                 LEGEND
                 ================================================= -->

            <div class="legend">


                <div class="legend-item">
                    <span class="legend-color casual"></span>
                    Casual Leave
                </div>


                <div class="legend-item">
                    <span class="legend-color earned"></span>
                    Earned Leave
                </div>


                <div class="legend-item">
                    <span class="legend-color half-pay"></span>
                    Half Pay Leave
                </div>


                <div class="legend-item">
                    <span class="legend-color commuted"></span>
                    Commuted Leave
                </div>


                <div class="legend-item">
                    <span class="legend-color special"></span>
                    Special Casual Leave
                </div>


                <div class="legend-item">
                    <span class="legend-color lwa"></span>
                    Leave Without Allowance
                </div>


                <div class="legend-item">
                    <span class="legend-color legend-holiday"></span>
                    Holiday
                </div>


            </div>


        </div>

    </div>

</div>


<script>

/* =========================================================
   DATABASE DATA
   ========================================================= */

/*
 * Actual employee leave records from PHP/database.
 */

const leaveData = <?php echo $leave_json ?: "{}"; ?>;


/*
 * All holidays from the holidays table.
 *
 * This includes:
 *
 * - CSV imported holidays
 * - Holidays manually added by admin
 */

const holidayData = <?php echo $holiday_json ?: "{}"; ?>;


/* =========================================================
   CALENDAR STATE
   ========================================================= */

/*
 * Month currently displayed.
 */

let currentDate = new Date();


/*
 * Real current date.
 *
 * This is used ONLY for the today's highlight.
 */

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

    /*
     * Short names are used inside the calendar
     * so the calendar remains compact.
     */

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

        default:
            label = leave.name;
            break;
    }


    leaveElement.textContent = label;


    /*
     * Tooltip with full leave information.
     */

    let tooltip =
        leave.name;


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

    holidayElement.className = "holiday";


    /*
     * Display the actual holiday description.
     */

    holidayElement.textContent =
        holiday.description;


    /*
     * Full information on hover.
     */

    let tooltip =
        holiday.description;


    if (holiday.day) {

        tooltip +=
            " | " +
            holiday.day;
    }


    holidayElement.title = tooltip;


    return holidayElement;
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


    /* =====================================================
       UPDATE MONTH TITLE
       ===================================================== */

    document.getElementById(
        "monthYear"
    ).textContent =
        `${monthNames[month]} ${year}`;


    /* =====================================================
       CLEAR CALENDAR
       ===================================================== */

    const calendarGrid =
        document.getElementById(
            "calendarGrid"
        );

    calendarGrid.innerHTML = "";


    /* =====================================================
       MONTH INFORMATION
       ===================================================== */

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


    /* =====================================================
       EMPTY DAYS BEFORE MONTH
       ===================================================== */

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
       CREATE EACH DAY
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


        /* =================================================
           DATE NUMBER
           ================================================= */

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
           TODAY HIGHLIGHT
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
           ================================================= */

        if (
            leaveData[dateKey] &&
            Array.isArray(
                leaveData[dateKey]
            )
        ) {

            leaveData[dateKey].forEach(
                function(leave) {

                    const leaveLabel =
                        createLeaveLabel(
                            leave
                        );

                    dayElement.appendChild(
                        leaveLabel
                    );

                }
            );
        }


        /* =================================================
           HOLIDAY
           ================================================= */

        if (
            holidayData[dateKey]
        ) {

            const holidayLabel =
                createHolidayLabel(
                    holidayData[dateKey]
                );

            dayElement.appendChild(
                holidayLabel
            );
        }


        /* =================================================
           ADD DAY TO CALENDAR
           ================================================= */

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
   INITIALIZE
   ========================================================= */

renderCalendar();

</script>


</body>
</html>