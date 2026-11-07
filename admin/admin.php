<?php

session_start();

require_once "../db.php";


/* =========================================================
   ADMIN ACCESS CHECK
========================================================= */

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}


/* =========================================================
   SUMMARY DATA FROM DATABASE
========================================================= */


/* =========================================================
   TOTAL EMPLOYEES
========================================================= */

$total_employees = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM employees
    WHERE status = 'active'
");

$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();

$total_employees = (int)($row["total"] ?? 0);

$stmt->close();


/* =========================================================
   EMPLOYEES ON LEAVE TODAY
========================================================= */

$on_leave_today = 0;

$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT employee_id) AS total
    FROM leave_records
    WHERE CURDATE() BETWEEN from_date AND to_date
");

$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();

$on_leave_today = (int)($row["total"] ?? 0);

$stmt->close();


/* =========================================================
   TOTAL LEAVE RECORDS
========================================================= */

$total_leave_records = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM leave_records
");

$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();

$total_leave_records = (int)($row["total"] ?? 0);

$stmt->close();


/* =========================================================
   PENDING EL SURRENDER REQUESTS
========================================================= */

$pending_surrender_count = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM el_surrender
    WHERE status = 'PENDING'
");

$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();

$pending_surrender_count = (int)($row["total"] ?? 0);

$stmt->close();


/* =========================================================
   RECENT LEAVE RECORDS
========================================================= */

$recent_leave_records = [];

$stmt = $conn->prepare("
    SELECT
        lr.id,
        e.name AS employee_name,
        e.employee_id,
        lt.code AS leave_code,
        lt.name AS leave_type,
        lr.from_date,
        lr.to_date,
        lr.days_taken,
        lr.created_at
    FROM leave_records lr

    INNER JOIN employees e
        ON lr.employee_id = e.id

    INNER JOIN leave_types lt
        ON lr.leave_type_id = lt.id

    ORDER BY
        lr.created_at DESC,
        lr.id DESC

    LIMIT 4
");

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $recent_leave_records[] = $row;

}

$stmt->close();


/* =========================================================
   UPCOMING HOLIDAYS
========================================================= */

$upcoming_holidays = [];

$stmt = $conn->prepare("
    SELECT
        holiday_date,
        day,
        description,
        source_type
    FROM holidays
    WHERE holiday_date >= CURDATE()
    ORDER BY holiday_date ASC
    LIMIT 3
");

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $upcoming_holidays[] = $row;

}

$stmt->close();


/* =========================================================
   RECENT ACTIVITIES
   ALL VALUES COME FROM MYSQL
========================================================= */

$recent_activities = [];

$stmt = $conn->prepare("
    SELECT
        lr.id,
        e.name AS employee_name,
        e.employee_id,
        lt.code AS leave_code,
        lt.name AS leave_type,
        lr.from_date,
        lr.to_date,
        lr.days_taken,
        lr.created_at
    FROM leave_records lr

    INNER JOIN employees e
        ON lr.employee_id = e.id

    INNER JOIN leave_types lt
        ON lr.leave_type_id = lt.id

    ORDER BY
        lr.created_at DESC,
        lr.id DESC

    LIMIT 3
");

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $recent_activities[] = $row;

}

$stmt->close();

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
        Admin Dashboard | EMMRC Leave Management
    </title>

    <link
        rel="stylesheet"
        href="../css/style.css?v=2"
    >

    <style>

        /* =================================================
           EL SURRENDER SUMMARY CARD
        ================================================= */

        .surrender-summary {
            cursor: pointer;
        }


        .surrender-summary-link {

            text-decoration: none;

            color: inherit;

            display: flex;

            align-items: center;

            gap: 14px;

            width: 100%;

        }


        .surrender-summary-link > div:last-child {
            flex: 1;
        }


        .surrender-summary:hover {
            transform: translateY(-2px);
        }


        .surrender-summary .summary-icon {

            background: #f0e9ff;

            color: #7048c8;

        }


        .surrender-summary strong {
            color: #7048c8;
        }


        /* =================================================
           KEEP FOUR SUMMARY CARDS IN ONE ROW
        ================================================= */

        @media (min-width: 1100px) {

            .admin-summary {

                grid-template-columns:
                    repeat(4, minmax(0, 1fr));

            }

        }


        /* =================================================
           TABLET
        ================================================= */

        @media (min-width: 700px) and (max-width: 1099px) {

            .admin-summary {

                grid-template-columns:
                    repeat(2, minmax(0, 1fr));

            }

        }


        /* =================================================
           MOBILE
        ================================================= */

        @media (max-width: 699px) {

            .admin-summary {

                grid-template-columns:
                    1fr;

            }

        }


        /* =================================================
           RECENT ACTIVITY DETAILS
        ================================================= */

        .activity-item p {

            margin: 3px 0;

        }


        .activity-leave-details {

            margin-top: 4px;

            font-size: 13px;

            color: #66758a;

            line-height: 1.6;

        }


        .activity-leave-details strong {

            color: #314563;

        }


        .activity-item small {

            display: block;

            margin-top: 4px;

        }

    </style>


    <link rel="stylesheet" href="../css/responsive.css?v=1">
</head>


<body class="admin-page">


<!-- =========================================================
     ADMIN HEADER
========================================================= -->

<header class="admin-header">

    <div class="admin-brand">

        <div class="admin-logo">

            <img
                src="../assets/emmrc logo.png"
                alt="EMMRC Logo"
            >

        </div>


        <div class="admin-brand-divider"></div>


        <div class="admin-brand-text">

            <strong>
                Leave Management
            </strong>

            <span>
                Admin Portal
            </span>

        </div>

    </div>


    <div class="admin-user">

        <div class="admin-avatar">
            A
        </div>


        <div class="admin-user-details">

            <strong>
                Admin
            </strong>

            <span>
                Administrator
            </span>

        </div>


        <a
            href="../login.php?action=logout"
            class="admin-logout"
        >
            ↪ Logout
        </a>

    </div>

</header>



<!-- =========================================================
     ADMIN CONTAINER
========================================================= -->

<main class="admin-container">


    <!-- =====================================================
         WELCOME SECTION
    ====================================================== -->

    <section class="admin-welcome">

        <div>

            <h1>
                Welcome, Admin
            </h1>

            <p>
                Manage employee leave records and leave accounts.
            </p>

        </div>

        <div class="welcome-icon">
            📋
        </div>

    </section>



    <!-- =====================================================
         SUMMARY CARDS
    ====================================================== -->

    <section class="admin-summary">


        <!-- =================================================
             TOTAL EMPLOYEES
        ================================================== -->

        <div class="admin-summary-card employees-summary">

            <div class="summary-icon">
                👥
            </div>

            <div>

                <span>
                    Total Employees
                </span>

                <strong>
                    <?php echo $total_employees; ?>
                </strong>

                <small>
                    Registered employees
                </small>

            </div>

        </div>



        <!-- =================================================
             ON LEAVE TODAY
        ================================================== -->

        <div class="admin-summary-card leave-summary">

            <div class="summary-icon">
                📅
            </div>

            <div>

                <span>
                    On Leave Today
                </span>

                <strong>
                    <?php echo $on_leave_today; ?>
                </strong>

                <small>
                    Employees currently on leave
                </small>

            </div>

        </div>



        <!-- =================================================
             LEAVE RECORDS
        ================================================== -->

        <div class="admin-summary-card records-summary">

            <div class="summary-icon">
                📄
            </div>

            <div>

                <span>
                    Leave Records
                </span>

                <strong>
                    <?php echo $total_leave_records; ?>
                </strong>

                <small>
                    Total records entered
                </small>

            </div>

        </div>



        <!-- =================================================
             EL SURRENDER
        ================================================== -->

        <div
            class="admin-summary-card surrender-summary"
            id="elSurrenderSummary"
        >

            <a
                href="el_surrender_approval.php"
                class="surrender-summary-link"
                onclick="
                    sessionStorage.setItem(
                        'admin_dashboard_scroll_position',
                        window.scrollY
                    );
                "
            >

                <div class="summary-icon">
                    ↻
                </div>


                <div>

                    <span>
                        EL Surrender
                    </span>

                    <strong>
                        <?php
                        echo $pending_surrender_count;
                        ?>
                    </strong>

                    <small>
                        Requests to review
                    </small>

                </div>

            </a>

        </div>


    </section>



    <!-- =====================================================
         SEARCH EMPLOYEE
    ====================================================== -->

    <section class="admin-search-card">

        <div class="admin-search-title">

            <h2>
                Search Employee
            </h2>

            <p>
                Search by Employee ID or employee name.
            </p>

        </div>


        <form
            class="admin-search-box"
            method="GET"
            action="employees.php"
        >

            <input
                type="text"
                name="search"
                placeholder="Search Employee ID / Name..."
            >

            <button type="submit">
                Search
            </button>

        </form>

    </section>



    <!-- =====================================================
         MAIN GRID
    ====================================================== -->

    <section class="admin-main-grid">


        <!-- =================================================
             RECENT LEAVE RECORDS
        ================================================== -->

        <div class="admin-records-card">

            <div class="admin-section-heading">

                <div>

                    <h2>
                        Recent Leave Records
                    </h2>

                    <p>
                        Recently entered employee leave details.
                    </p>

                </div>


                <a
                    href="leave_records.php"
                    class="view-all-button"
                    style="text-decoration:none;"
                    onclick="
                        sessionStorage.setItem(
                            'admin_dashboard_scroll_position',
                            window.scrollY
                        );
                    "
                >
                    View All
                </a>

            </div>


            <div class="admin-table-wrapper">

                <table class="admin-table">

                    <thead>

                        <tr>

                            <th>
                                Employee
                            </th>

                            <th>
                                Employee ID
                            </th>

                            <th>
                                Leave Type
                            </th>

                            <th>
                                From
                            </th>

                            <th>
                                To
                            </th>

                            <th>
                                Days
                            </th>

                            <th>
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (empty($recent_leave_records)): ?>

                        <tr>

                            <td
                                colspan="7"
                                style="
                                    text-align:center;
                                    padding:30px;
                                    color:#7b8799;
                                "
                            >

                                No leave records found.

                            </td>

                        </tr>


                    <?php else: ?>


                        <?php foreach (
                            $recent_leave_records
                            as $record
                        ): ?>


                            <?php

                            $badge_class = "casual";

                            switch (
                                strtoupper(
                                    $record["leave_code"]
                                )
                            ) {

                                case "EL":
                                    $badge_class = "earned";
                                    break;

                                case "HPL":
                                    $badge_class = "halfpay";
                                    break;

                                case "COM":
                                    $badge_class = "commuted";
                                    break;

                                case "SCL":
                                    $badge_class = "special";
                                    break;

                                case "LWA":
                                    $badge_class = "lwa";
                                    break;

                                case "CL":
                                default:
                                    $badge_class = "casual";
                                    break;

                            }

                            ?>


                            <tr>


                                <!-- EMPLOYEE -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $record["employee_name"]
                                    );
                                    ?>

                                </td>


                                <!-- EMPLOYEE ID -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $record["employee_id"]
                                    );
                                    ?>

                                </td>


                                <!-- LEAVE TYPE -->

                                <td>

                                    <span
                                        class="leave-badge
                                        <?php echo $badge_class; ?>"
                                    >

                                        <?php
                                        echo htmlspecialchars(
                                            $record["leave_type"]
                                        );
                                        ?>

                                    </span>

                                </td>


                                <!-- FROM -->

                                <td>

                                    <?php
                                    echo date(
                                        "d-m-Y",
                                        strtotime(
                                            $record["from_date"]
                                        )
                                    );
                                    ?>

                                </td>


                                <!-- TO -->

                                <td>

                                    <?php
                                    echo date(
                                        "d-m-Y",
                                        strtotime(
                                            $record["to_date"]
                                        )
                                    );
                                    ?>

                                </td>


                                <!-- DAYS -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $record["days_taken"]
                                    );
                                    ?>

                                </td>


                                <!-- ACTION -->

                                <td>

                                    <a
                                        href="../leave_history.php?id=<?php
                                            echo urlencode(
                                                $record["employee_id"]
                                            );
                                        ?>&from=admin"
                                        class="table-view-button"
                                        style="
                                            text-decoration:none;
                                            display:inline-flex;
                                            align-items:center;
                                            justify-content:center;
                                        "
                                        onclick="
                                            sessionStorage.setItem(
                                                'admin_dashboard_scroll_position',
                                                window.scrollY
                                            );
                                        "
                                    >
                                        View
                                    </a>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>



        <!-- =================================================
             QUICK ACTIONS
        ================================================== -->

        <div class="admin-actions-card">

            <div class="admin-section-heading">

                <div>

                    <h2>
                        Quick Actions
                    </h2>

                    <p>
                        Common administrative actions.
                    </p>

                </div>

            </div>


            <div class="admin-actions-list">


                <!-- ENTER LEAVE -->

                <a
                    href="enter_leave.php"
                    class="admin-action-button enter-leave"
                    style="text-decoration:none;"
                    onclick="
                        sessionStorage.setItem(
                            'admin_dashboard_scroll_position',
                            window.scrollY
                        );
                    "
                >

                    <span class="action-icon">
                        📅
                    </span>

                    <span>
                        Enter Leave
                    </span>

                    <strong>
                        ›
                    </strong>

                </a>



                <!-- MANAGE EMPLOYEES -->

                <a
                    href="employees.php"
                    class="admin-action-button manage-employee"
                    style="text-decoration:none;"
                    onclick="
                        sessionStorage.setItem(
                            'admin_dashboard_scroll_position',
                            window.scrollY
                        );
                    "
                >

                    <span class="action-icon">
                        👥
                    </span>

                    <span>
                        Manage Employees
                    </span>

                    <strong>
                        ›
                    </strong>

                </a>



                <!-- MANAGE HOLIDAYS -->

                <a
                    href="manage_holidays.php"
                    class="admin-action-button manage-holiday"
                    style="text-decoration:none;"
                    onclick="
                        sessionStorage.setItem(
                            'admin_dashboard_scroll_position',
                            window.scrollY
                        );
                    "
                >

                    <span class="action-icon">
                        🗓
                    </span>

                    <span>
                        Manage Holidays
                    </span>

                    <strong>
                        ›
                    </strong>

                </a>



                <!-- EL SURRENDER APPROVAL -->

                <a
                    href="el_surrender_approval.php"
                    class="admin-action-button surrender-action"
                    style="text-decoration:none;"
                    onclick="
                        sessionStorage.setItem(
                            'admin_dashboard_scroll_position',
                            window.scrollY
                        );
                    "
                >

                    <span class="action-icon">
                        ↻
                    </span>

                    <span>
                        EL Surrender Approval
                    </span>

                    <strong>
                        ›
                    </strong>

                </a>



                <!-- VIEW REPORTS -->

                <a
                    href="leave_records.php"
                    class="admin-action-button report-action"
                    style="text-decoration:none;"
                    onclick="
                        sessionStorage.setItem(
                            'admin_dashboard_scroll_position',
                            window.scrollY
                        );
                    "
                >

                    <span class="action-icon">
                        📊
                    </span>

                    <span>
                        View Reports
                    </span>

                    <strong>
                        ›
                    </strong>

                </a>


                <!-- LEAVE SETTINGS -->

                <a
                    href="leave_settings.php"
                    class="admin-action-button"
                    style="text-decoration:none;"
                    onclick="
                        sessionStorage.setItem(
                            'admin_dashboard_scroll_position',
                            window.scrollY
                        );
                    "
                >

                    <span class="action-icon">
                        ⚙️
                    </span>

                    <span>
                        Leave Settings
                    </span>

                    <strong>
                        ›
                    </strong>

                </a>


            </div>

        </div>


    </section>



    <!-- =====================================================
         BOTTOM SECTION
    ====================================================== -->

    <section class="admin-bottom-grid">


        <!-- =================================================
             UPCOMING HOLIDAYS
        ================================================== -->

        <div class="admin-info-card">

            <div class="admin-section-heading">

                <div>

                    <h2>
                        Upcoming Holidays
                    </h2>

                    <p>
                        Government and common holidays.
                    </p>

                </div>

            </div>


            <div class="holiday-list">


            <?php if (empty($upcoming_holidays)): ?>

                <div
                    style="
                        padding:20px 0;
                        color:#7b8799;
                        text-align:center;
                    "
                >
                    No upcoming holidays found.
                </div>

            <?php else: ?>


                <?php foreach (
                    $upcoming_holidays
                    as $holiday
                ): ?>


                    <?php

                    $holiday_timestamp =
                        strtotime(
                            $holiday["holiday_date"]
                        );

                    $holiday_day =
                        date(
                            "d",
                            $holiday_timestamp
                        );

                    $holiday_month =
                        strtoupper(
                            date(
                                "M",
                                $holiday_timestamp
                            )
                        );

                    ?>


                    <div class="holiday-item">


                        <div class="holiday-date">

                            <strong>
                                <?php
                                echo $holiday_day;
                                ?>
                            </strong>

                            <span>
                                <?php
                                echo $holiday_month;
                                ?>
                            </span>

                        </div>


                        <div>

                            <strong>
                                <?php
                                echo htmlspecialchars(
                                    $holiday["description"]
                                );
                                ?>
                            </strong>

                            <span>
                                Public Holiday
                            </span>

                        </div>


                    </div>


                <?php endforeach; ?>


            <?php endif; ?>


            </div>

        </div>



        <!-- =================================================
             RECENT ACTIVITY
        ================================================== -->

        <div class="admin-info-card">

            <div class="admin-section-heading">

                <div>

                    <h2>
                        Recent Activities
                    </h2>

                    <p>
                        Latest leave-related activity.
                    </p>

                </div>

            </div>


            <div class="activity-list">


            <?php if (empty($recent_activities)): ?>

                <div
                    style="
                        padding:20px 0;
                        color:#7b8799;
                        text-align:center;
                    "
                >
                    No recent activities found.
                </div>

            <?php else: ?>


                <?php foreach (
                    $recent_activities
                    as $activity
                ): ?>


                    <div class="activity-item">

                        <span
                            class="activity-dot"
                        ></span>


                        <div>

                            <!-- =================================
                                 ACTIVITY TITLE
                            ================================== -->

                            <strong>
                                Leave record added
                            </strong>


                            <!-- =================================
                                 EMPLOYEE NAME + ID
                            ================================== -->

                            <p>

                                <?php
                                echo htmlspecialchars(
                                    $activity["employee_name"]
                                );
                                ?>

                                <span
                                    style="
                                        color:#8a97aa;
                                        font-size:12px;
                                    "
                                >
                                    (
                                    <?php
                                    echo htmlspecialchars(
                                        $activity["employee_id"]
                                    );
                                    ?>
                                    )
                                </span>

                            </p>


                            <!-- =================================
                                 LEAVE DETAILS FROM MYSQL
                            ================================== -->

                            <div
                                class="activity-leave-details"
                            >

                                <strong>
                                    <?php
                                    echo htmlspecialchars(
                                        $activity["leave_type"]
                                    );
                                    ?>
                                </strong>

                                &nbsp;•&nbsp;

                                <?php
                                echo htmlspecialchars(
                                    $activity["days_taken"]
                                );
                                ?>

                                day<?php
                                echo (
                                    (float)$activity["days_taken"] != 1
                                    ? "s"
                                    : ""
                                );
                                ?>

                                <br>


                                <?php
                                echo date(
                                    "d-m-Y",
                                    strtotime(
                                        $activity["from_date"]
                                    )
                                );
                                ?>

                                to

                                <?php
                                echo date(
                                    "d-m-Y",
                                    strtotime(
                                        $activity["to_date"]
                                    )
                                );
                                ?>

                            </div>


                            <!-- =================================
                                 CREATED DATE/TIME FROM MYSQL
                            ================================== -->

                            <small>

                                <?php
                                echo date(
                                    "d M Y, h:i A",
                                    strtotime(
                                        $activity["created_at"]
                                    )
                                );
                                ?>

                            </small>

                        </div>

                    </div>


                <?php endforeach; ?>


            <?php endif; ?>


            </div>

        </div>


    </section>


</main>



<!-- =========================================================
     SAVE & RESTORE ADMIN DASHBOARD SCROLL POSITION
========================================================= -->

<script>

(function () {

    const storageKey =
        "admin_dashboard_scroll_position";


    /* =====================================================
       SAVE CURRENT SCROLL POSITION
    ===================================================== */

    function saveScrollPosition() {

        sessionStorage.setItem(
            storageKey,
            window.scrollY
        );

    }


    /* =====================================================
       SAVE BEFORE LEAVING PAGE
    ===================================================== */

    window.addEventListener(
        "beforeunload",
        function () {

            saveScrollPosition();

        }
    );


    /* =====================================================
       SAVE WHEN CLICKING LINKS
    ===================================================== */

    document.addEventListener(
        "click",
        function (event) {

            const link =
                event.target.closest("a");

            if (!link) {
                return;
            }

            saveScrollPosition();

        }
    );


    /* =====================================================
       RESTORE SCROLL POSITION
    ===================================================== */

    window.addEventListener(
        "load",
        function () {

            const savedPosition =
                sessionStorage.getItem(
                    storageKey
                );

            if (
                savedPosition !== null &&
                savedPosition !== ""
            ) {

                setTimeout(
                    function () {

                        window.scrollTo(
                            0,
                            parseInt(
                                savedPosition,
                                10
                            )
                        );

                    },
                    100
                );

            }

        }
    );

})();

</script>


</body>

</html>