
<?php

session_start();

require_once __DIR__ . "/../db.php";

/* =========================================================
   ADMIN ACCESS CHECK
========================================================= */

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}

/* =========================================================
   MESSAGE FROM REDIRECT
========================================================= */

$approval_message = "";
$approval_message_type = "";

$message_code = $_GET["message"] ?? "";

if ($message_code === "approved") {

    $approval_message = "EL surrender request approved successfully.";
    $approval_message_type = "success";

} elseif ($message_code === "rejected") {

    $approval_message = "EL surrender request rejected successfully.";
    $approval_message_type = "success";

} elseif ($message_code === "error") {

    $approval_message = $_GET["error"] ?? "Unable to process the request.";
    $approval_message_type = "error";
}

/* =========================================================
   APPROVE / REJECT EL SURRENDER
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = trim($_POST["surrender_action"] ?? "");
    $surrender_id = (int)($_POST["surrender_id"] ?? 0);

    if (
        $surrender_id <= 0 ||
        !in_array($action, ["approve", "reject"], true)
    ) {
        header(
            "Location: el_surrender_approval.php?message=error&error=" .
            urlencode("Invalid surrender request.")
        );
        exit();
    }

    try {

        $conn->beginTransaction();

        /* =================================================
           GET AND LOCK PENDING REQUEST
        ================================================= */

        $request_stmt = $conn->prepare("
            SELECT
                es.id,
                es.employee_id,
                es.surrender_date,
                es.year,
                es.days,
                es.status,
                es.remarks,
                e.employee_id AS employee_code,
                e.name AS employee_name,
                e.designation,
                e.department
            FROM el_surrender es
            INNER JOIN employees e
                ON es.employee_id = e.id
            WHERE es.id = :surrender_id
              AND es.status = 'PENDING'
            LIMIT 1
            FOR UPDATE
        ");

        $request_stmt->execute([
            ":surrender_id" => $surrender_id
        ]);

        $request = $request_stmt->fetch();

        if (!$request) {
            throw new Exception(
                "This surrender request has already been processed or does not exist."
            );
        }

        $employee_db_id = (int)$request["employee_id"];
        $surrender_days = (float)$request["days"];
        $surrender_year = (int)$request["year"];

        if ($surrender_days <= 0) {
            throw new Exception("Invalid surrender days.");
        }

        /* =================================================
           APPROVE REQUEST
        ================================================= */

        if ($action === "approve") {

            /* =============================================
               CHECK 30-DAY FINANCIAL-YEAR LIMIT
            ============================================= */

            $surrender_date = $request["surrender_date"];
            $surrender_timestamp = strtotime($surrender_date);

            if ($surrender_timestamp === false) {
                throw new Exception("Invalid surrender date.");
            }

            $surrender_month = (int)date("n", $surrender_timestamp);
            $surrender_calendar_year = (int)date("Y", $surrender_timestamp);

            $financial_year_start = ($surrender_month >= 4)
                ? $surrender_calendar_year
                : $surrender_calendar_year - 1;

            $financial_year_start_date = sprintf(
                "%04d-04-01",
                $financial_year_start
            );

            $financial_year_end_date = sprintf(
                "%04d-04-01",
                $financial_year_start + 1
            );

            $fy_total_stmt = $conn->prepare("
                SELECT COALESCE(SUM(days), 0) AS total_days
                FROM el_surrender
                WHERE employee_id = :employee_id
                  AND surrender_date >= :start_date
                  AND surrender_date < :end_date
                  AND status IN ('PENDING', 'APPROVED')
                  AND id <> :surrender_id
            ");

            $fy_total_stmt->execute([
                ":employee_id" => $employee_db_id,
                ":start_date" => $financial_year_start_date,
                ":end_date" => $financial_year_end_date,
                ":surrender_id" => $surrender_id
            ]);

            $fy_total_row = $fy_total_stmt->fetch();

            $existing_fy_days = (float)($fy_total_row["total_days"] ?? 0);
            $proposed_fy_days = $existing_fy_days + $surrender_days;

            if ($proposed_fy_days > 30) {
                throw new Exception(
                    "Cannot approve this request. The employee has already requested or surrendered "
                    . number_format($existing_fy_days, 2)
                    . " days during financial year "
                    . $financial_year_start . "-" . ($financial_year_start + 1)
                    . ". The maximum allowed is 30 days per financial year."
                );
            }

            /* =============================================
               GET AND LOCK EL BALANCE
            ============================================= */

            $balance_stmt = $conn->prepare("
                SELECT
                    lb.id,
                    lb.closing_balance
                FROM leave_balances lb
                INNER JOIN leave_types lt
                    ON lb.leave_type_id = lt.id
                WHERE lb.employee_id = :employee_id
                  AND lt.code = 'EL'
                  AND lb.year = :year
                LIMIT 1
                FOR UPDATE
            ");

            $balance_stmt->execute([
                ":employee_id" => $employee_db_id,
                ":year" => $surrender_year
            ]);

            $balance_row = $balance_stmt->fetch();

            if (!$balance_row) {
                throw new Exception(
                    "EL balance record was not found for this employee."
                );
            }

            $current_el_balance = (float)$balance_row["closing_balance"];

            if ($surrender_days > $current_el_balance) {
                throw new Exception(
                    "Cannot approve this request because the employee's current EL balance is insufficient."
                );
            }

            /* =============================================
               DEDUCT EL BALANCE
            ============================================= */

            $update_balance_stmt = $conn->prepare("
                UPDATE leave_balances
                SET
                    surrendered = surrendered + :surrendered_days,
                    closing_balance = closing_balance - :deducted_days
                WHERE id = :balance_id
                  AND closing_balance >= :minimum_balance
            ");

            $update_balance_stmt->execute([
                ":surrendered_days" => $surrender_days,
                ":deducted_days" => $surrender_days,
                ":balance_id" => (int)$balance_row["id"],
                ":minimum_balance" => $surrender_days
            ]);

            if ($update_balance_stmt->rowCount() !== 1) {
                throw new Exception(
                    "The employee does not have enough EL balance for this surrender."
                );
            }

            /* =============================================
               CHANGE REQUEST STATUS
            ============================================= */

            $status_stmt = $conn->prepare("
                UPDATE el_surrender
                SET status = 'APPROVED'
                WHERE id = :surrender_id
                  AND status = 'PENDING'
            ");

            $status_stmt->execute([
                ":surrender_id" => $surrender_id
            ]);

            if ($status_stmt->rowCount() !== 1) {
                throw new Exception(
                    "This surrender request has already been processed."
                );
            }

            /* =============================================
               COMMIT
            ============================================= */

            $conn->commit();

            header(
                "Location: el_surrender_approval.php?message=approved"
            );
            exit();
        }

        /* =================================================
           REJECT REQUEST
        ================================================= */

        if ($action === "reject") {

            $status_stmt = $conn->prepare("
                UPDATE el_surrender
                SET status = 'REJECTED'
                WHERE id = :surrender_id
                  AND status = 'PENDING'
            ");

            $status_stmt->execute([
                ":surrender_id" => $surrender_id
            ]);

            if ($status_stmt->rowCount() !== 1) {
                throw new Exception(
                    "This surrender request has already been processed."
                );
            }

            $conn->commit();

            header(
                "Location: el_surrender_approval.php?message=rejected"
            );
            exit();
        }

    } catch (Throwable $e) {

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        error_log("EL surrender approval error: " . $e->getMessage());

        header(
            "Location: el_surrender_approval.php?message=error&error=" .
            urlencode("Unable to process the request. Please check the database or contact the administrator.")
        );

        exit();
    }
}

/* =========================================================
   PENDING EL SURRENDER COUNT
========================================================= */

$pending_surrender_count = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM el_surrender
    WHERE status = 'PENDING'
");

$stmt->execute();

$row = $stmt->fetch();

$pending_surrender_count = (int)($row["total"] ?? 0);

/* =========================================================
   PENDING EL SURRENDER REQUESTS
========================================================= */

$pending_surrender_requests = [];

$stmt = $conn->prepare("
    SELECT
        es.id,
        es.surrender_date,
        es.year,
        es.days,
        es.remarks,
        e.employee_id,
        e.name,
        e.designation,
        e.department
    FROM el_surrender es
    INNER JOIN employees e
        ON es.employee_id = e.id
    WHERE es.status = 'PENDING'
    ORDER BY
        es.surrender_date ASC,
        es.id ASC
");

$stmt->execute();

$pending_surrender_requests = $stmt->fetchAll();

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
        EL Surrender Approval | EMMRC Leave Management
    </title>

    <link
        rel="stylesheet"
        href="../css/style.css?v=2"
    >

    <style>

        /* =================================================
           PAGE
        ================================================= */

        .approval-page-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 24px;
            margin-top: 25px;
            box-shadow: 0 5px 20px rgba(30, 50, 90, 0.07);
        }

        .approval-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 22px;
        }

        .approval-heading h2 {
            margin: 0;
            color: #263b60;
            font-size: 20px;
        }

        .approval-heading p {
            margin: 6px 0 0;
            color: #7b8799;
            font-size: 12px;
        }

        .pending-count-badge {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 20px;
            background: #fff1dc;
            color: #d88916;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
        }

        .approval-message {
            padding: 13px 16px;
            border-radius: 9px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .approval-message.success {
            background: #eaf8f1;
            color: #168052;
            border: 1px solid #cceede;
        }

        .approval-message.error {
            background: #fff0f0;
            color: #c83d3d;
            border: 1px solid #f2cccc;
        }

        .approval-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .approval-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        .approval-table th {
            background: #f6f8fc;
            color: #68758a;
            font-size: 11px;
            font-weight: 700;
            text-align: left;
            padding: 13px;
            white-space: nowrap;
        }

        .approval-table td {
            color: #526078;
            font-size: 12px;
            padding: 14px 13px;
            border-bottom: 1px solid #edf0f5;
            vertical-align: middle;
        }

        .approval-table tbody tr:hover {
            background: #fafbfe;
        }

        .employee-name {
            display: block;
            color: #263b60;
            font-weight: 700;
        }

        .employee-id {
            display: block;
            margin-top: 3px;
            color: #8a94a6;
            font-size: 10px;
        }

        .days-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            padding: 5px 9px;
            border-radius: 6px;
            background: #eef1ff;
            color: #526ee8;
            font-weight: 700;
        }

        .status-pending {
            color: #e48f17;
            font-weight: 700;
        }

        .action-buttons {
            display: flex;
            gap: 7px;
            align-items: center;
        }

        .action-buttons form {
            margin: 0;
        }

        .approve-button,
        .reject-button {
            border: none;
            border-radius: 7px;
            padding: 8px 12px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        .approve-button {
            background: #eaf8f1;
            color: #168052;
        }

        .approve-button:hover {
            background: #d9f2e5;
        }

        .reject-button {
            background: #fff0f0;
            color: #c83d3d;
        }

        .reject-button:hover {
            background: #fbe0e0;
        }

        .no-requests {
            padding: 45px 20px;
            text-align: center;
            color: #7b8799;
            font-size: 13px;
        }

        .no-requests-icon {
            font-size: 30px;
            margin-bottom: 10px;
        }

        .approval-footer {
            margin-top: 22px;
            display: flex;
            justify-content: flex-start;
        }

        .back-dashboard-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 15px;
            border-radius: 8px;
            background: #f1f4f9;
            color: #526078;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
        }

        .back-dashboard-button:hover {
            background: #e7ebf2;
        }

        @media (max-width: 800px) {
            .approval-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .approval-page-card {
                padding: 18px;
            }

            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }

            .approve-button,
            .reject-button {
                width: 100%;
            }
        }

    </style>

    <link rel="stylesheet" href="../css/responsive.css?v=1">

</head>

<body class="admin-page">

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
            <strong>Leave Management</strong>
            <span>Admin Portal</span>
        </div>

    </div>

    <div class="admin-user">

        <div class="admin-avatar">
            A
        </div>

        <div class="admin-user-details">
            <strong>Admin</strong>
            <span>Administrator</span>
        </div>

        <a
            href="../login.php?action=logout"
            class="admin-logout"
        >
            ↪ Logout
        </a>

    </div>

</header>

<main class="admin-container">

    <section class="admin-welcome">

        <div>

            <h1>EL Surrender Approval</h1>

            <p>
                Review and process employee EL surrender requests.
            </p>

        </div>

        <div class="welcome-icon">
            ↻
        </div>

    </section>

    <?php if ($approval_message !== ""): ?>

        <div
            class="approval-message <?php echo htmlspecialchars($approval_message_type); ?>"
        >
            <?php echo htmlspecialchars($approval_message); ?>
        </div>

    <?php endif; ?>

    <section class="approval-page-card">

        <div class="approval-heading">

            <div>

                <h2>Pending EL Surrender Requests</h2>

                <p>
                    Approve or reject surrender requests submitted by employees.
                </p>

            </div>

            <span class="pending-count-badge">
                <?php echo $pending_surrender_count; ?>
            </span>

        </div>

        <div class="approval-table-wrapper">

            <?php if (empty($pending_surrender_requests)): ?>

                <div class="no-requests">

                    <div class="no-requests-icon">
                        ✓
                    </div>

                    <strong>
                        No pending EL surrender requests.
                    </strong>

                    <div style="margin-top:6px;">
                        All surrender requests have been processed.
                    </div>

                </div>

            <?php else: ?>

                <table class="approval-table">

                    <thead>

                        <tr>
                            <th>Employee</th>
                            <th>Designation</th>
                            <th>Department</th>
                            <th>Surrender Date</th>
                            <th>Days</th>
                            <th>Remarks</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($pending_surrender_requests as $request): ?>

                        <tr>

                            <td>

                                <span class="employee-name">
                                    <?php echo htmlspecialchars($request["name"]); ?>
                                </span>

                                <span class="employee-id">
                                    ID:
                                    <?php echo htmlspecialchars($request["employee_id"]); ?>
                                </span>

                            </td>

                            <td>
                                <?php echo htmlspecialchars($request["designation"]); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($request["department"]); ?>
                            </td>

                            <td>
                                <?php
                                echo date(
                                    "d-m-Y",
                                    strtotime($request["surrender_date"])
                                );
                                ?>
                            </td>

                            <td>

                                <span class="days-badge">
                                    <?php echo htmlspecialchars((string)$request["days"]); ?>
                                </span>

                            </td>

                            <td>

                                <?php
                                $remarks = trim($request["remarks"] ?? "");

                                echo $remarks !== ""
                                    ? htmlspecialchars($remarks)
                                    : "—";
                                ?>

                            </td>

                            <td>
                                <span class="status-pending">Pending</span>
                            </td>

                            <td>

                                <div class="action-buttons">

                                    <form
                                        method="POST"
                                        action=""
                                        onsubmit="return confirm('Approve this EL surrender request? The surrendered days will be deducted from the employee EL balance.');"
                                    >

                                        <input
                                            type="hidden"
                                            name="surrender_action"
                                            value="approve"
                                        >

                                        <input
                                            type="hidden"
                                            name="surrender_id"
                                            value="<?php echo (int)$request["id"]; ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="approve-button"
                                        >
                                            Approve
                                        </button>

                                    </form>

                                    <form
                                        method="POST"
                                        action=""
                                        onsubmit="return confirm('Reject this EL surrender request?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="surrender_action"
                                            value="reject"
                                        >

                                        <input
                                            type="hidden"
                                            name="surrender_id"
                                            value="<?php echo (int)$request["id"]; ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="reject-button"
                                        >
                                            Reject
                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

        <div class="approval-footer">

            <a
                href="admin.php"
                class="back-dashboard-button"
            >
                ← Back to Dashboard
            </a>

        </div>

    </section>

</main>

</body>
</html>
