<?php

/* =========================================================
   SESSION & DATABASE
   ========================================================= */

session_start();

require_once "db.php";


/* =========================================================
   ACCESS CONTROL
   ========================================================= */

if (!isset($_SESSION["role"])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION["role"];

if ($role !== "employee" && $role !== "admin") {
    header("Location: index.php");
    exit();
}


/* =========================================================
   DETERMINE EMPLOYEE / SOURCE
   ========================================================= */

if ($role === "employee") {

    /*
     * Employee can only submit surrender
     * for their own employee ID.
     */

    if (!isset($_SESSION["employee_id"])) {
        header("Location: index.php");
        exit();
    }

    $employee_id = $_SESSION["employee_id"];

    $from = "employee";

} elseif ($role === "admin") {

    /*
     * Admin can access an employee from
     * the employee profile.
     *
     * POST fallback is used so the employee
     * information is not lost after submission.
     */

    $employee_id = trim(
        $_GET["id"] ??
        $_POST["employee_id"] ??
        ""
    );

    $from =
        $_GET["from"] ??
        $_POST["from"] ??
        "employee";

    if ($employee_id === "") {

        header("Location: admin/employees.php");
        exit();

    }

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
        department
    FROM employees
    WHERE employee_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "s",
    $employee_id
);

$stmt->execute();

$result = $stmt->get_result();

$employee = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   INVALID EMPLOYEE
   ========================================================= */

if (!$employee) {

    if ($role === "admin") {

        header("Location: admin/employees.php");

    } else {

        header("Location: index.php");

    }

    exit();

}


$employee_db_id = (int)$employee["id"];

$current_year = (int)date("Y"); // Keep for EL balance lookup.

$current_month = (int)date("n");

$current_financial_year_start =
    ($current_month >= 4)
        ? $current_year
        : $current_year - 1;

$current_financial_year_end =
    $current_financial_year_start + 1;

/* =========================================================
   MESSAGE
   ========================================================= */

$message = "";

$message_type = "";


/* =========================================================
   LOAD CURRENT EL BALANCE
   ========================================================= */

$el_balance = 0;

$balance_stmt = $conn->prepare("
    SELECT
        lb.closing_balance
    FROM leave_balances lb

    INNER JOIN leave_types lt
        ON lb.leave_type_id = lt.id

    WHERE lb.employee_id = ?
      AND lt.code = 'EL'
      AND lb.year = ?

    LIMIT 1
");

$balance_stmt->bind_param(
    "ii",
    $employee_db_id,
    $current_year
);

$balance_stmt->execute();

$balance_result =
    $balance_stmt->get_result();

$balance_row =
    $balance_result->fetch_assoc();

if ($balance_row) {

    $el_balance =
        (float)$balance_row["closing_balance"];

}

$balance_stmt->close();


/* =========================================================
   PROCESS SURRENDER FORM
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /*
     * Preserve employee ID from POST.
     * For employees, it must still match
     * the logged-in employee.
     */

    if ($role === "employee") {

        $posted_employee_id =
            trim($_POST["employee_id"] ?? "");

        if (
            $posted_employee_id !==
            (string)$_SESSION["employee_id"]
        ) {

            $message =
                "Invalid employee information.";

            $message_type = "error";

        }

    }


    /* =====================================================
       GET FORM VALUES
       ===================================================== */

    if ($message === "") {

        $days =
            (int)($_POST["days"] ?? 0);

        $surrender_date =
            trim(
                $_POST["surrender_date"] ?? ""
            );

        $remarks =
            trim(
                $_POST["remarks"] ?? ""
            );


        /* =================================================
           BASIC VALIDATION
           ================================================= */

        if (
            $days < 1 ||
            $days > 30
        ) {

            $message =
                "Surrender days must be between 1 and 30.";

            $message_type = "error";

        } elseif (
            $surrender_date === ""
        ) {

            $message =
                "Please select the surrender date.";

            $message_type = "error";

        } else {


            /* =============================================
               VALIDATE DATE
               ============================================= */

            $date_object =
                DateTime::createFromFormat(
                    "Y-m-d",
                    $surrender_date
                );

            $valid_date =
                $date_object &&
                $date_object->format("Y-m-d") ===
                $surrender_date;


            if (!$valid_date) {

                $message =
                    "Invalid surrender date.";

                $message_type = "error";

            } elseif (
                $surrender_date <
                 sprintf(
                      "%04d-04-01",
                      $current_financial_year_start
                    )
                   ||
                    $surrender_date >=
                    sprintf(
                       "%04d-04-01",
                        $current_financial_year_end
                    )
                ) {

                  $message =
                     "Surrender date must be within the current financial year (April 1 to March 31).";

                    $message_type = "error";

            } elseif (
                $days > $el_balance
            ) {

                $message =
                    "Surrender days cannot exceed the current EL balance.";

                $message_type = "error";

            } else {


                /* =========================================
                   CHECK YEARLY 30-DAY LIMIT
                   ========================================= */

                $financial_year_start_date = sprintf(
                    "%04d-04-01",
                    $current_financial_year_start
                );

                $financial_year_end_date = sprintf(
                    "%04d-04-01",
                    $current_financial_year_end
                );

                $used_stmt = $conn->prepare("
                    SELECT COALESCE(SUM(days), 0) AS total_days
                    FROM el_surrender
                    WHERE employee_id = ?
                      AND surrender_date >= ?
                      AND surrender_date < ?
                      AND status IN ('PENDING', 'APPROVED')
                ");

                $used_stmt->bind_param(
                    "iss",
                    $employee_db_id,
                    $financial_year_start_date,
                    $financial_year_end_date
                );

                $used_stmt->execute();

                $used_result =
                    $used_stmt->get_result();

                $used_row =
                    $used_result->fetch_assoc();

                $used_days =
                    (float)(
                        $used_row["total_days"]
                        ?? 0
                    );

                $used_stmt->close();


                /* =========================================
                   CHECK REMAINING YEARLY LIMIT
                   ========================================= */

                if (
                    ($used_days + $days) > 30
                ) {

                    $remaining =
                        max(
                            0,
                            30 - $used_days
                        );

                    $message =
                        "Only " .
                        $remaining .
                        " surrender day(s) remain available for this year.";

                    $message_type = "error";

                } else {


                    /* =====================================
                       DETERMINE STATUS
                       ===================================== */

                    if (
                        $role === "admin" &&
                        $from === "profile"
                    ) {

                        /*
                         * Admin recording directly
                         * from employee profile.
                         */

                        $status = "APPROVED";

                    } else {

                        /*
                         * Employee request requires
                         * admin approval.
                         */

                        $status = "PENDING";

                    }


                    /* =====================================
                       DATABASE TRANSACTION
                       ===================================== */

                    $conn->begin_transaction();


                    try {


                        /* =================================
                           SAVE SURRENDER REQUEST
                           ================================= */

                        $insert_stmt = $conn->prepare("
                            INSERT INTO el_surrender
                            (
                                employee_id,
                                surrender_date,
                                year,
                                days,
                                status,
                                remarks
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?
                            )
                        ");

                        $insert_stmt->bind_param(
                            "isiiss",
                            $employee_db_id,
                            $surrender_date,
                            $current_financial_year_start,
                            $days,
                            $status,
                            $remarks
                        );


                        if (
                            !$insert_stmt->execute()
                        ) {

                            throw new Exception(
                                "Unable to save the surrender request."
                            );

                        }


                        $insert_stmt->close();


                        /* =================================
                           APPROVED SURRENDER
                           DEDUCT EL
                           ================================= */

                        if (
                            $status === "APPROVED"
                        ) {

                            $update_stmt = $conn->prepare("
                                UPDATE leave_balances lb

                                INNER JOIN leave_types lt
                                    ON lb.leave_type_id = lt.id

                                SET
                                    lb.surrendered =
                                        lb.surrendered + ?,

                                    lb.closing_balance =
                                        lb.closing_balance - ?

                                WHERE lb.employee_id = ?

                                  AND lt.code = 'EL'

                                  AND lb.year = ?

                                  AND lb.closing_balance >= ?
                            ");

                            $update_stmt->bind_param(
                                "ddiid",
                                $days,
                                $days,
                                $employee_db_id,
                                $current_year,
                                $days
                            );


                            if (
                                !$update_stmt->execute() ||
                                $update_stmt->affected_rows < 1
                            ) {

                                $update_stmt->close();

                                throw new Exception(
                                    "Unable to update the EL balance."
                                );

                            }


                            $update_stmt->close();


                            /*
                             * Update displayed balance.
                             */

                            $el_balance -= $days;

                        }


                        /* =================================
                           COMMIT
                           ================================= */

                        $conn->commit();


                        if (
                            $status === "APPROVED"
                        ) {

                            $message =
                                "EL surrender recorded successfully. " .
                                $days .
                                " day(s) deducted from EL.";

                        } else {

                            $message =
                                "EL surrender request submitted for admin approval.";

                        }


                        $message_type = "success";


                    } catch (Throwable $e) {


                        $conn->rollback();


                        $message =
                            $e->getMessage();

                        $message_type = "error";

                    }

                }

            }

        }

    }

}


/* =========================================================
   LOAD PREVIOUS SURRENDER REQUESTS
   ========================================================= */

$surrender_history = [];

$history_stmt = $conn->prepare("
    SELECT
        surrender_date,
        days,
        remarks,
        status

    FROM el_surrender

    WHERE employee_id = ?

    ORDER BY
        surrender_date DESC,
        id DESC
");

$history_stmt->bind_param(
    "i",
    $employee_db_id
);

$history_stmt->execute();

$history_result =
    $history_stmt->get_result();


while (
    $row =
    $history_result->fetch_assoc()
) {

    $surrender_history[] =
        $row;

}


$history_stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>EL Surrender</title>

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

        .surrender-page {
            min-height: 100vh;
            height: auto;
            overflow: visible;
        }

        body:has(.surrender-page) {
            overflow-x: hidden;
            overflow-y: auto;
        }

        .surrender-container {
            width: calc(100% - 80px);
            max-width: 1100px;
            margin: 30px auto;
        }


        /* =================================================
           BACK LINK
           ================================================= */

        .surrender-back {
            display: inline-block;
            margin-bottom: 18px;
            color: #526ee8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }


        /* =================================================
           MAIN CARD
           ================================================= */

        .surrender-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 28px;
            box-shadow:
                0 5px 20px
                rgba(30, 50, 90, 0.08);
        }

        .surrender-header {
            margin-bottom: 25px;
        }

        .surrender-header h1 {
            margin: 0;
            color: #173b72;
            font-size: 24px;
        }

        .surrender-header p {
            margin: 6px 0 0;
            color: #7b8799;
            font-size: 13px;
        }


        /* =================================================
           EMPLOYEE INFORMATION
           ================================================= */

        .employee-info {
            display: grid;
            grid-template-columns:
                repeat(4, 1fr);
            gap: 14px;
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
           EL BALANCE
           ================================================= */

        .balance-box {
            background: #eef1ff;
            border: 1px solid #dce2ff;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .balance-text span {
            display: block;
            color: #68758a;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .balance-text strong {
            color: #526ee8;
            font-size: 25px;
        }

        .balance-note {
            color: #7b8799;
            font-size: 11px;
        }


        /* =================================================
           FORM SECTION
           ================================================= */

        .form-section {
            border-top: 1px solid #edf0f5;
            padding-top: 24px;
        }

        .form-section h2 {
            margin: 0 0 18px;
            color: #263b60;
            font-size: 17px;
        }

        .form-grid {
            display: grid;
            grid-template-columns:
                repeat(2, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            color: #526078;
            font-size: 12px;
            font-weight: 600;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #dfe4ee;
            border-radius: 9px;
            padding: 11px 12px;
            font-family: inherit;
            font-size: 13px;
            color: #263b60;
            outline: none;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #526ee8;
        }

        .form-group textarea {
            min-height: 90px;
            resize: vertical;
        }

        .form-hint {
            color: #8a94a6;
            font-size: 10px;
        }


        /* =================================================
           FORM BUTTONS
           ================================================= */

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 25px;
        }

        .cancel-button,
        .el-submit-button {
            padding: 11px 20px;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .cancel-button {
            background: #f1f3f7;
            color: #66748b;
        }

        .el-submit-button {
            border: none;
            background: #526ee8;
            color: #ffffff;
            padding: 12px 20px;
            transition: 0.2s ease;
        }

        .el-submit-button:hover {
            background: #435ed4;
            transform: translateY(-1px);
        }


        /* =================================================
           MESSAGE
           ================================================= */

        .surrender-message {
            padding: 12px 14px;
            border-radius: 9px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .surrender-message.success {
            background: #eaf8f1;
            color: #168052;
            border: 1px solid #cceede;
        }

        .surrender-message.error {
            background: #fff0f0;
            color: #c83d3d;
            border: 1px solid #f2cccc;
        }


        /* =================================================
           PREVIOUS SURRENDER HISTORY
           ================================================= */

        .previous-section {
            margin-top: 30px;
            border-top: 1px solid #edf0f5;
            padding-top: 24px;
        }

        .previous-section h2 {
            margin: 0 0 15px;
            color: #263b60;
            font-size: 17px;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .history-table th {
            background: #f5f7fb;
            color: #647087;
            font-size: 11px;
            text-align: left;
            padding: 12px;
        }

        .history-table td {
            color: #526078;
            font-size: 12px;
            padding: 13px 12px;
            border-bottom: 1px solid #edf0f5;
        }

        .status-pending {
            color: #e48f17;
            font-weight: 600;
        }

        .status-approved {
            color: #23a86c;
            font-weight: 600;
        }

        .status-rejected {
            color: #d9534f;
            font-weight: 600;
        }


        /* =================================================
           RESPONSIVE DESIGN
           ================================================= */

        @media (max-width: 800px) {

            .surrender-container {
                width: calc(100% - 30px);
            }

            .employee-info {
                grid-template-columns:
                    repeat(2, 1fr);
            }

        }

        @media (max-width: 600px) {

            .employee-info,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: auto;
            }

            .balance-box {
                align-items: flex-start;
                flex-direction: column;
                gap: 10px;
            }

        }

    </style>


    <link rel="stylesheet" href="css/responsive.css?v=1">
</head>


<body>

<div class="surrender-page">

    <div class="surrender-container">


        <!-- =================================================
             BACK NAVIGATION
             ================================================= -->

        <?php if ($from === "profile"): ?>

            <a
                href="admin/employee_profile.php?id=<?php
                    echo urlencode($employee_id);
                ?>"
                class="surrender-back"
            >
                ← Back to Employee Profile
            </a>

        <?php else: ?>

            <a
                href="employee/dashboard.php"
                class="surrender-back"
            >
                ← Back to Dashboard
            </a>

        <?php endif; ?>


        <!-- =================================================
             MAIN CARD
             ================================================= -->

        <div class="surrender-card">


            <!-- =================================================
                 PAGE HEADER
                 ================================================= -->

            <div class="surrender-header">

                <h1>

                    <?php
                    echo (
                        $from === "profile"
                    )
                        ? "Record EL Surrender"
                        : "EL Surrender";
                    ?>

                </h1>

                <p>

                    <?php
                    echo (
                        $from === "profile"
                    )
                        ? "Record earned leave surrender for this employee"
                        : "Submit earned leave surrender request";
                    ?>

                </p>

            </div>


            <!-- =================================================
                 EMPLOYEE INFORMATION
                 ================================================= -->

            <div class="employee-info">


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
                 CURRENT EL BALANCE
                 ================================================= -->

            <div class="balance-box">

                <div class="balance-text">

                    <span>
                        Current Earned Leave Balance
                    </span>

                    <strong>

                        <?php

                        echo htmlspecialchars(
                            rtrim(
                                rtrim(
                                    number_format(
                                        $el_balance,
                                        2,
                                        ".",
                                        ""
                                    ),
                                    "0"
                                ),
                                "."
                            )
                        );

                        ?>

                        Days

                    </strong>

                </div>


                <div class="balance-note">

                    Maximum surrender allowed:
                    30 days per financial year (April 1 – March 31)

                </div>

            </div>


            <!-- =================================================
                 MESSAGE
                 ================================================= -->

            <?php if ($message !== ""): ?>

                <div
                    class="surrender-message
                    <?php
                    echo htmlspecialchars(
                        $message_type
                    );
                    ?>"
                >

                    <?php
                    echo htmlspecialchars(
                        $message
                    );
                    ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 SURRENDER FORM
                 ================================================= -->

            <div class="form-section">

                <h2>
                    Surrender Request
                </h2>


                <form
                    id="surrenderForm"
                    method="POST"
                    action=""
                >


                    <!-- Preserve employee context -->

                    <input
                        type="hidden"
                        name="employee_id"
                        value="<?php
                            echo htmlspecialchars(
                                $employee_id
                            );
                        ?>"
                    >


                    <input
                        type="hidden"
                        name="from"
                        value="<?php
                            echo htmlspecialchars(
                                $from
                            );
                        ?>"
                    >


                    <div class="form-grid">


                        <!-- =================================================
                             NUMBER OF DAYS
                             ================================================= -->

                        <div class="form-group">

                            <label for="days">
                                Number of Days
                            </label>

                            <input
                                type="number"
                                id="days"
                                name="days"
                                min="1"
                                max="30"
                                value="<?php
                                    echo isset($_POST["days"])
                                        ? htmlspecialchars(
                                            $_POST["days"]
                                        )
                                        : "";
                                ?>"
                                required
                            >

                            <span class="form-hint">

                                Maximum 30 days can be surrendered
                                per financial year (April 1 – March 31).

                            </span>

                        </div>


                        <!-- =================================================
                             SURRENDER DATE
                             ================================================= -->

                        <div class="form-group">

                            <label for="surrender_date">

                                Surrender Date

                            </label>

                            <input
                                type="date"
                                id="surrender_date"
                                name="surrender_date"
                                value="<?php
                                    echo isset(
                                        $_POST[
                                            "surrender_date"
                                        ]
                                    )
                                        ? htmlspecialchars(
                                            $_POST[
                                                "surrender_date"
                                            ]
                                        )
                                        : "";
                                ?>"
                                required
                            >

                        </div>


                        <!-- =================================================
                             REMARKS
                             ================================================= -->

                        <div
                            class="form-group full-width"
                        >

                            <label for="remarks">

                                Remarks

                            </label>

                            <textarea
                                id="remarks"
                                name="remarks"
                                placeholder="Enter remarks if required..."
                            ><?php
                            echo isset(
                                $_POST["remarks"]
                            )
                                ? htmlspecialchars(
                                    $_POST["remarks"]
                                )
                                : "";
                            ?></textarea>

                        </div>


                    </div>


                    <!-- =================================================
                         FORM ACTIONS
                         ================================================= -->

                    <div class="form-actions">


                        <?php if ($from === "profile"): ?>

                            <a
                                href="admin/employee_profile.php?id=<?php
                                    echo urlencode(
                                        $employee_id
                                    );
                                ?>"
                                class="cancel-button"
                            >
                                Cancel
                            </a>

                        <?php else: ?>

                            <a
                                href="employee/dashboard.php"
                                class="cancel-button"
                            >
                                Cancel
                            </a>

                        <?php endif; ?>


                        <button
                            type="submit"
                            class="el-submit-button"
                        >

                            <?php
                            echo (
                                $from === "profile"
                            )
                                ? "Record EL Surrender"
                                : "Submit Surrender Request";
                            ?>

                        </button>


                    </div>


                </form>

            </div>


            <!-- =================================================
                 PREVIOUS SURRENDER REQUESTS
                 ================================================= -->

            <div class="previous-section">

                <h2>
                    Previous Surrender Requests
                </h2>


                <table class="history-table">

                    <thead>

                        <tr>

                            <th>
                                Date
                            </th>

                            <th>
                                Days
                            </th>

                            <th>
                                Remarks
                            </th>

                            <th>
                                Status
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                        <?php if (
                            !empty(
                                $surrender_history
                            )
                        ): ?>


                            <?php foreach (
                                $surrender_history
                                as $history
                            ): ?>

                                <tr>


                                    <!-- Date -->

                                    <td>

                                        <?php

                                        echo htmlspecialchars(
                                            date(
                                                "d-m-Y",
                                                strtotime(
                                                    $history[
                                                        "surrender_date"
                                                    ]
                                                )
                                            )
                                        );

                                        ?>

                                    </td>


                                    <!-- Days -->

                                    <td>

                                        <?php

                                        echo htmlspecialchars(
                                            $history[
                                                "days"
                                            ]
                                        );

                                        ?>

                                    </td>


                                    <!-- Remarks -->

                                    <td>

                                        <?php

                                        echo htmlspecialchars(
                                            $history[
                                                "remarks"
                                            ] ?? ""
                                        );

                                        ?>

                                    </td>


                                    <!-- Status -->

                                    <td>

                                        <?php

                                        $history_status =
                                            strtoupper(
                                                trim(
                                                    $history[
                                                        "status"
                                                    ]
                                                )
                                            );

                                        ?>


                                        <?php if (
                                            $history_status ===
                                            "PENDING"
                                        ): ?>

                                            <span
                                                class="status-pending"
                                            >
                                                Pending
                                            </span>


                                        <?php elseif (
                                            $history_status ===
                                            "APPROVED"
                                        ): ?>

                                            <span
                                                class="status-approved"
                                            >
                                                Approved
                                            </span>


                                        <?php elseif (
                                            $history_status ===
                                            "REJECTED"
                                        ): ?>

                                            <span
                                                class="status-rejected"
                                            >
                                                Rejected
                                            </span>


                                        <?php else: ?>

                                            <span>
                                                <?php
                                                echo htmlspecialchars(
                                                    $history_status
                                                );
                                                ?>
                                            </span>

                                        <?php endif; ?>


                                    </td>


                                </tr>

                            <?php endforeach; ?>


                        <?php else: ?>


                            <tr>

                                <td
                                    colspan="4"
                                    style="text-align:center;"
                                >
                                    No previous surrender requests.
                                </td>

                            </tr>


                        <?php endif; ?>


                    </tbody>

                </table>

            </div>


        </div>

    </div>

</div>


<!-- =========================================================
     SURRENDER FORM VALIDATION
     ========================================================= -->

<script>

document
    .getElementById("surrenderForm")
    .addEventListener(
        "submit",
        function (event) {

            const days =
                parseInt(
                    document
                        .getElementById("days")
                        .value,
                    10
                );

            const surrenderDate =
                document
                    .getElementById(
                        "surrender_date"
                    )
                    .value;


            /* =============================================
               DAYS VALIDATION
               ============================================= */

            if (
                isNaN(days) ||
                days < 1 ||
                days > 30
            ) {

                event.preventDefault();

                alert(
                    "Surrender days must be between 1 and 30."
                );

                return;

            }


            /* =============================================
               DATE VALIDATION
               ============================================= */

            if (!surrenderDate) {

                event.preventDefault();

                alert(
                    "Please select the surrender date."
                );

                return;

            }


            /*
             * Do not prevent submission here.
             *
             * PHP will:
             * - validate the date
             * - validate EL balance
             * - check 30-day yearly limit
             * - save the request
             * - update balance if approved
             */

        }
    );

</script>

</body>

</html>