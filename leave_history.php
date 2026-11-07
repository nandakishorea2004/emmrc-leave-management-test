
<?php

/* =========================================================
   SESSION & DATABASE
========================================================= */

session_start();

require_once __DIR__ . "/db.php";


/* =========================================================
   ACCESS CONTROL
========================================================= */

if (!isset($_SESSION["role"])) {
    header("Location: index.php");
    exit();
}


/* =========================================================
   EMPLOYEE SELECTION
========================================================= */

$from = $_GET["from"] ?? "profile";

if ($_SESSION["role"] === "admin") {

    $employee_id = trim($_GET["id"] ?? "");

    if ($employee_id === "") {
        header("Location: admin/employees.php");
        exit();
    }

} elseif ($_SESSION["role"] === "employee") {

    $employee_id = $_SESSION["employee_id"] ?? "";

    if ($employee_id === "") {
        header("Location: index.php");
        exit();
    }

} else {

    header("Location: index.php");
    exit();
}


/* =========================================================
   LOAD EMPLOYEE FROM DATABASE
========================================================= */

$stmt = $conn->prepare("
    SELECT
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

$stmt->execute([$employee_id]);

$employee = $stmt->fetch(PDO::FETCH_ASSOC);


/* =========================================================
   EMPLOYEE NOT FOUND
========================================================= */

if (!$employee) {

    if ($_SESSION["role"] === "admin") {
        header("Location: admin/employees.php");
    } else {
        header("Location: index.php");
    }

    exit();
}


/* =========================================================
   DATABASE LEAVE HISTORY
========================================================= */

$leave_history = [];

$stmt = $conn->prepare("
    SELECT
        lt.name AS type,
        lr.from_date,
        lr.to_date,
        lr.days_taken AS days,
        lr.reason,
        lr.remarks
    FROM leave_records lr

    INNER JOIN employees e
        ON lr.employee_id = e.id

    INNER JOIN leave_types lt
        ON lr.leave_type_id = lt.id

    WHERE e.employee_id = ?

    ORDER BY
        lr.from_date DESC,
        lr.id DESC
");

$stmt->execute([$employee_id]);

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);


foreach ($results as $row) {

    $leave_history[] = [

        "type" => $row["type"],

        "from" => date(
            "d-m-Y",
            strtotime($row["from_date"])
        ),

        "to" => date(
            "d-m-Y",
            strtotime($row["to_date"])
        ),

        "days" => $row["days"],

        "reason" => $row["reason"] ?? "",

        "remarks" => $row["remarks"] ?? ""

    ];
}


/* =========================================================
   TOTAL RECORDS
========================================================= */

$total_records = count($leave_history);


/* =========================================================
   EMPLOYEE NAME / OTHER DATA
========================================================= */

$employee_name = $employee["name"];

$designation = $employee["designation"];

$department = $employee["department"];

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
        Leave History -
        <?php echo htmlspecialchars($employee_name); ?>
    </title>

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

        .history-page {
            min-height: 100vh;
            height: auto;
            overflow: visible;
        }

        body:has(.history-page) {
            overflow-x: hidden;
            overflow-y: auto;
        }

        .history-container {
            width: calc(100% - 80px);
            max-width: 1250px;
            margin: 30px auto;
        }


        /* =================================================
           BACK NAVIGATION
           ================================================= */

        .history-back {
            display: inline-block;
            margin-bottom: 18px;
            color: #526ee8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .history-back:hover {
            text-decoration: none;
        }


        /* =================================================
           MAIN CARD
           ================================================= */

        .history-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 28px;
            box-shadow:
                0 5px 20px rgba(30, 50, 90, 0.08);
        }


        /* =================================================
           PAGE TITLE
           ================================================= */

        .history-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .history-title h1 {
            margin: 0;
            color: #173b72;
            font-size: 24px;
        }

        .history-title p {
            margin: 6px 0 0;
            color: #7b8799;
            font-size: 13px;
        }

        .record-count {
            background: #eef1ff;
            color: #526ee8;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }


        /* =================================================
           EMPLOYEE INFORMATION
           ================================================= */

        .employee-info {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .info-box {
            background: #f8f9fc;
            border: 1px solid #edf0f6;
            border-radius: 10px;
            padding: 14px;
        }

        .info-box span {
            display: block;
            color: #8993a5;
            font-size: 11px;
            margin-bottom: 6px;
        }

        .info-box strong {
            color: #263b60;
            font-size: 14px;
        }


        /* =================================================
           LEAVE HISTORY TABLE
           ================================================= */

        .history-table-wrapper {
            overflow-x: auto;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .history-table th {
            background: #f5f7fb;
            color: #647087;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            padding: 13px 12px;
            border-bottom: 1px solid #e8ebf2;
        }

        .history-table td {
            padding: 15px 12px;
            font-size: 13px;
            color: #42516b;
            border-bottom: 1px solid #edf0f5;
        }

        .history-table tr:last-child td {
            border-bottom: none;
        }

        .leave-type {
            font-weight: 700;
            color: #263b60;
        }

        .days-badge {
            display: inline-block;
            min-width: 25px;
            text-align: center;
            background: #eef1ff;
            color: #526ee8;
            padding: 5px 8px;
            border-radius: 12px;
            font-weight: 700;
        }

        .status-recorded {
            color: #26855b;
            font-weight: 600;
        }


        /* =================================================
           EMPTY STATE
           ================================================= */

        .empty-history {
            padding: 50px 20px;
            text-align: center;
            color: #7b8799;
            font-size: 13px;
        }

        .empty-history-title {
            display: block;
            margin-bottom: 6px;
            color: #526078;
            font-size: 15px;
            font-weight: 600;
        }


        /* =================================================
           RESPONSIVE DESIGN
           ================================================= */

        @media (max-width: 800px) {

            .history-container {
                width: calc(100% - 30px);
            }

            .employee-info {
                grid-template-columns: repeat(2, 1fr);
            }

            .history-title {
                align-items: flex-start;
                gap: 15px;
            }

        }


        @media (max-width: 500px) {

            .employee-info {
                grid-template-columns: 1fr;
            }

            .history-card {
                padding: 18px;
            }

            .history-title {
                flex-direction: column;
                align-items: flex-start;
            }

        }

    </style>


    <link rel="stylesheet" href="css/responsive.css?v=1">
</head>


<body>


<div class="history-page">


    <div class="history-container">


        <!-- =================================================
             BACK NAVIGATION
             ================================================= -->

        <?php if ($_SESSION["role"] === "admin"): ?>

            <?php if ($from === "admin"): ?>

                <a
                    href="admin/admin.php"
                    class="history-back"
                >
                    ← Back to Dashboard
                </a>

            <?php else: ?>

                <a
                    href="admin/employee_profile.php?id=<?php echo urlencode($employee_id); ?>"
                    class="history-back"
                >
                    ← Back to Employee Profile
                </a>

            <?php endif; ?>

        <?php else: ?>

            <a
                href="employee/dashboard.php"
                class="history-back"
            >
                ← Back to Dashboard
            </a>

        <?php endif; ?>


        <!-- =================================================
             MAIN HISTORY CARD
             ================================================= -->

        <div class="history-card">


            <!-- =================================================
                 PAGE TITLE
                 ================================================= -->

            <div class="history-title">

                <div>

                    <h1>
                        Leave History
                    </h1>

                    <p>
                        Complete leave records of the employee
                    </p>

                </div>


                <div class="record-count">

                    <?php echo $total_records; ?>

                    <?php
                    echo ($total_records === 1)
                        ? " Record"
                        : " Records";
                    ?>

                </div>

            </div>


            <!-- =================================================
                 EMPLOYEE INFORMATION
                 ================================================= -->

            <div class="employee-info">


                <!-- Employee ID -->

                <div class="info-box">

                    <span>
                        Employee ID
                    </span>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $employee["employee_id"]
                        );
                        ?>
                    </strong>

                </div>


                <!-- Employee Name -->

                <div class="info-box">

                    <span>
                        Employee Name
                    </span>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $employee["name"]
                        );
                        ?>
                    </strong>

                </div>


                <!-- Designation -->

                <div class="info-box">

                    <span>
                        Designation
                    </span>

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $employee["designation"]
                        );
                        ?>
                    </strong>

                </div>


                <!-- Department -->

                <div class="info-box">

                    <span>
                        Department
                    </span>

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
                 LEAVE HISTORY
                 ================================================= -->

            <div class="history-table-wrapper">


                <?php if (!empty($leave_history)): ?>


                    <table class="history-table">


                        <thead>

                            <tr>

                                <th>
                                    Leave Type
                                </th>

                                <th>
                                    From Date
                                </th>

                                <th>
                                    To Date
                                </th>

                                <th>
                                    Days
                                </th>

                                <th>
                                    Reason
                                </th>

                                <th>
                                    Remarks
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach (
                            $leave_history
                            as $leave
                        ): ?>


                            <tr>


                                <!-- Leave Type -->

                                <td>

                                    <span class="leave-type">

                                        <?php
                                        echo htmlspecialchars(
                                            $leave["type"]
                                        );
                                        ?>

                                    </span>

                                </td>


                                <!-- From Date -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $leave["from"]
                                    );
                                    ?>

                                </td>


                                <!-- To Date -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $leave["to"]
                                    );
                                    ?>

                                </td>


                                <!-- Days -->

                                <td>

                                    <span class="days-badge">

                                        <?php
                                        echo htmlspecialchars(
                                            $leave["days"]
                                        );
                                        ?>

                                    </span>

                                </td>


                                <!-- Reason -->

                                <td>

                                    <?php

                                    if (
                                        trim(
                                            $leave["reason"]
                                        ) !== ""
                                    ) {

                                        echo htmlspecialchars(
                                            $leave["reason"]
                                        );

                                    } else {

                                        echo "-";

                                    }

                                    ?>

                                </td>


                                <!-- Remarks -->

                                <td>

                                    <?php

                                    if (
                                        trim(
                                            $leave["remarks"]
                                        ) !== ""
                                    ) {

                                        echo '<span class="status-recorded">'
                                            . htmlspecialchars(
                                                $leave["remarks"]
                                            )
                                            . '</span>';

                                    } else {

                                        echo "-";

                                    }

                                    ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                        </tbody>


                    </table>


                <?php else: ?>


                    <!-- =================================================
                         NO RECORDS
                         ================================================= -->

                    <div class="empty-history">

                        <span class="empty-history-title">
                            No Leave Records Found
                        </span>

                        No leave has been recorded for this employee yet.

                    </div>


                <?php endif; ?>


            </div>


        </div>


    </div>


</div>


</body>

</html>
