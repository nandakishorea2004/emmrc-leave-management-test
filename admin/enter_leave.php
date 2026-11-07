
<?php
session_start();
require_once "../db.php";

/* =========================================================
   ADMIN ACCESS CHECK
   ========================================================= */
if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit();
}

/* =========================================================
   HELPER FUNCTIONS
   ========================================================= */
function showMessageAndRedirect(string $message, string $url = "enter_leave.php"): void
{
    echo "<script>alert(" . json_encode($message) . ");window.location.href="
        . json_encode($url) . ";</script>";
    exit();
}

function validDate(string $date): bool
{
    $d = DateTime::createFromFormat("!Y-m-d", $date);
    return $d !== false && $d->format("Y-m-d") === $date;
}

function returnUrl(string $from, string $employeeId): string
{
    if ($from === "profile" && $employeeId !== "") {
        return "enter_leave.php?id=" . rawurlencode($employeeId) . "&from=profile";
    }
    return "enter_leave.php";
}

/* =========================================================
   LOAD HOLIDAYS
   ========================================================= */
$holidays = [];
$holidayDates = [];

$holidayStmt = $conn->prepare("
    SELECT holiday_date, day, description, source_type
    FROM holidays
    ORDER BY holiday_date ASC
");
$holidayStmt->execute();
$holidayResult = $holidayStmt->get_result();

while ($holiday = $holidayResult->fetch_assoc()) {
    $holidays[] = [
        "date" => $holiday["holiday_date"],
        "day" => $holiday["day"],
        "description" => $holiday["description"],
        "type" => $holiday["source_type"]
    ];
    $holidayDates[] = $holiday["holiday_date"];
}
$holidayStmt->close();

/* =========================================================
   SAVE LEAVE RECORD SAFELY
   ========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $formEmployeeId = trim($_POST["employee_id"] ?? "");
    $leaveTypeCode = strtoupper(trim($_POST["leave_type"] ?? ""));
    $fromDate = trim($_POST["from_date"] ?? "");
    $toDate = trim($_POST["to_date"] ?? "");
    $reason = trim($_POST["reason"] ?? "");
    $remarks = trim($_POST["remarks"] ?? "");

    $from = trim($_POST["form_from"] ?? ($_GET["from"] ?? "admin"));
    $redirectUrl = returnUrl($from, $formEmployeeId);

    if (
        $formEmployeeId === "" ||
        $leaveTypeCode === "" ||
        !validDate($fromDate) ||
        !validDate($toDate)
    ) {
        showMessageAndRedirect("Please fill all required leave details.", $redirectUrl);
    }

    if ($toDate < $fromDate) {
        showMessageAndRedirect("To Date cannot be earlier than From Date.", $redirectUrl);
    }

    /*
     * Calculate days on the server.
     * CL excludes dates listed in the holidays table.
     * Other leave types count all calendar days.
     */
    $start = new DateTime($fromDate);
    $end = new DateTime($toDate);
    $period = new DatePeriod(
        $start,
        new DateInterval("P1D"),
        (clone $end)->modify("+1 day")
    );

    $daysTaken = 0;
    $holidaySet = array_flip($holidayDates);

    foreach ($period as $date) {
        $dateString = $date->format("Y-m-d");

        if ($leaveTypeCode === "CL" && isset($holidaySet[$dateString])) {
            continue;
        }

        $daysTaken++;
    }

    if ($daysTaken <= 0) {
        showMessageAndRedirect(
            "The selected dates do not contain any chargeable leave days.",
            $redirectUrl
        );
    }

    /* -----------------------------------------------------
       GET ACTIVE EMPLOYEE
       ----------------------------------------------------- */
    $employeeStmt = $conn->prepare("
        SELECT id, employee_id, name, gender, status
        FROM employees
        WHERE employee_id = ?
        LIMIT 1
    ");
    $employeeStmt->bind_param("s", $formEmployeeId);
    $employeeStmt->execute();
    $employee = $employeeStmt->get_result()->fetch_assoc();
    $employeeStmt->close();

    if (!$employee) {
        showMessageAndRedirect("Employee record not found.", $redirectUrl);
    }

    if (strtolower((string)$employee["status"]) !== "active") {
        showMessageAndRedirect("This employee is not active.", $redirectUrl);
    }

    $employeeDbId = (int)$employee["id"];

    /* -----------------------------------------------------
       GET ACTIVE LEAVE TYPE
       ----------------------------------------------------- */
    $leaveTypeStmt = $conn->prepare("
        SELECT id, code, name
        FROM leave_types
        WHERE code = ? AND status = 1
        LIMIT 1
    ");
    $leaveTypeStmt->bind_param("s", $leaveTypeCode);
    $leaveTypeStmt->execute();
    $leaveType = $leaveTypeStmt->get_result()->fetch_assoc();
    $leaveTypeStmt->close();

    if (!$leaveType) {
        showMessageAndRedirect("Invalid or inactive leave type.", $redirectUrl);
    }

    $leaveTypeId = (int)$leaveType["id"];

    /*
     * Basic gender checks only.
     * These checks do not establish full KSR eligibility.
     */
    $gender = strtolower(trim((string)$employee["gender"]));

    if ($leaveTypeCode === "ML" && $gender !== "female") {
        showMessageAndRedirect(
            "Maternity Leave is not available for this employee under the configured gender check.",
            $redirectUrl
        );
    }

    if ($leaveTypeCode === "PL" && $gender !== "male") {
        showMessageAndRedirect(
            "Paternity Leave is not available for this employee under the configured gender check.",
            $redirectUrl
        );
    }

    /*
     * The current balance system uses the From Date year.
     * Cross-year leave needs year-wise allocation if required
     * by the applicable leave rules.
     */
    $year = (int)date("Y", strtotime($fromDate));
    $createdBy = (int)($_SESSION["user_id"] ?? 0);

    /* -----------------------------------------------------
       TRANSACTION
       ----------------------------------------------------- */
    try {
        $conn->begin_transaction();


       // =====================================================
// DUPLICATE LEAVE CHECK
// =====================================================

$duplicateStmt = $conn->prepare("
    SELECT id
    FROM leave_records
    WHERE employee_id = ?
      AND leave_type_id = ?
      AND from_date = ?
      AND to_date = ?
      AND session_type = 'FULL'
      AND status = 'ACTIVE'
    LIMIT 1
    FOR UPDATE
");


if (!$duplicateStmt) {
    throw new RuntimeException(
        "Unable to prepare duplicate leave check: " . $conn->error
    );
}

$duplicateStmt->bind_param(
    "iiss",
    $employeeDbId,
    $leaveTypeId,
    $fromDate,
    $toDate
);

if (!$duplicateStmt->execute()) {
    $error = $duplicateStmt->error;
    $duplicateStmt->close();

    throw new RuntimeException(
        "Unable to check duplicate leave: " . $error
    );
}

$duplicateResult = $duplicateStmt->get_result();

if ($duplicateResult->num_rows > 0) {
    $duplicateStmt->close();

    throw new Exception(
        "This exact leave has already been entered for this employee."
    );
}

$duplicateStmt->close();

// =====================================================
// END DUPLICATE LEAVE CHECK
// =====================================================


        /*
         * CL / EL / HPL:
         * Check and lock the balance before recording leave.
         */
        if (in_array($leaveTypeCode, ["CL", "EL", "HPL"], true)) {
            $balanceStmt = $conn->prepare("
                SELECT id, used, closing_balance
                FROM leave_balances
                WHERE employee_id = ?
                  AND leave_type_id = ?
                  AND year = ?
                LIMIT 1
                FOR UPDATE
            ");
            $balanceStmt->bind_param(
                "iii",
                $employeeDbId,
                $leaveTypeId,
                $year
            );
            $balanceStmt->execute();
            $balance = $balanceStmt->get_result()->fetch_assoc();
            $balanceStmt->close();

            if (!$balance) {
                throw new RuntimeException(
                    "A balance record for " . $leaveTypeCode .
                    " was not found for " . $year . ". No leave was saved."
                );
            }

            $currentBalance = (float)$balance["closing_balance"];

            if ($currentBalance < $daysTaken) {
                throw new RuntimeException(
                    "Insufficient " . $leaveTypeCode .
                    " balance. Available: " . $currentBalance .
                    " days; required: " . $daysTaken . " days."
                );
            }

            $newUsed = (float)$balance["used"] + $daysTaken;
            $newClosing = $currentBalance - $daysTaken;
            $balanceId = (int)$balance["id"];

            $updateStmt = $conn->prepare("
                UPDATE leave_balances
                SET used = ?, closing_balance = ?
                WHERE id = ?
            ");
            $updateStmt->bind_param(
                "ddi",
                $newUsed,
                $newClosing,
                $balanceId
            );
            if (!$updateStmt->execute()) {
    throw new RuntimeException(
        "Unable to update the " . $leaveTypeCode .
        " balance: " . $updateStmt->error
    );
}

if ($updateStmt->affected_rows !== 1) {
    throw new RuntimeException(
        "Expected to update one " . $leaveTypeCode .
        " balance row, but updated " . $updateStmt->affected_rows . " rows."
    );
}

$updateStmt->close();
        }

        /*
         * COM:
         * One day of COM deducts two days from HPL.
         */
        elseif ($leaveTypeCode === "COM") {
            $hplStmt = $conn->prepare("
                SELECT id
                FROM leave_types
                WHERE code = 'HPL' AND status = 1
                LIMIT 1
            ");
            $hplStmt->execute();
            $hplType = $hplStmt->get_result()->fetch_assoc();
            $hplStmt->close();

            if (!$hplType) {
                throw new RuntimeException("The active HPL leave type was not found.");
            }

            $hplTypeId = (int)$hplType["id"];

            $hplBalanceStmt = $conn->prepare("
                SELECT id, used, closing_balance
                FROM leave_balances
                WHERE employee_id = ?
                  AND leave_type_id = ?
                  AND year = ?
                LIMIT 1
                FOR UPDATE
            ");
            $hplBalanceStmt->bind_param(
                "iii",
                $employeeDbId,
                $hplTypeId,
                $year
            );
            $hplBalanceStmt->execute();
            $hplBalance = $hplBalanceStmt->get_result()->fetch_assoc();
            $hplBalanceStmt->close();

            if (!$hplBalance) {
                throw new RuntimeException(
                    "The HPL balance record was not found for " . $year . "."
                );
            }

            $hplDaysToDeduct = $daysTaken * 2;
            $currentHpl = (float)$hplBalance["closing_balance"];

            if ($currentHpl < $hplDaysToDeduct) {
                throw new RuntimeException(
                    "Insufficient HPL balance for commuted leave. Available: " .
                    $currentHpl . " days; required: " . $hplDaysToDeduct . " days."
                );
            }

            $newHplUsed = (float)$hplBalance["used"] + $hplDaysToDeduct;
            $newHplClosing = $currentHpl - $hplDaysToDeduct;
            $hplBalanceId = (int)$hplBalance["id"];

            $updateHplStmt = $conn->prepare("
                UPDATE leave_balances
                SET used = ?, closing_balance = ?
                WHERE id = ?
            ");
            $updateHplStmt->bind_param(
                "ddi",
                $newHplUsed,
                $newHplClosing,
                $hplBalanceId
            );
            if (!$updateHplStmt->execute()) {
    throw new RuntimeException(
        "Unable to update the HPL balance: " . $updateHplStmt->error
    );
}

if ($updateHplStmt->affected_rows !== 1) {
    throw new RuntimeException(
        "Expected to update one HPL balance row, but updated " .
        $updateHplStmt->affected_rows . " rows."
    );
}

$updateHplStmt->close();
        }

        /*
         * Insert the leave record only after balance checks
         * and balance updates have succeeded.
         */

/*
 * Track whether this leave actually deducted a balance.
 *
 * CL, EL, HPL: their own balance was deducted.
 * COM: HPL balance was deducted.
 * SCL, LWA, ML, PL: no balance deduction.
 */
$balanceDeducted = in_array(
    $leaveTypeCode,
    ["CL", "EL", "HPL", "COM"],
    true
) ? 1 : 0;

$balanceRestored = 0;

$saveStmt = $conn->prepare("
    INSERT INTO leave_records
    (
        employee_id,
        leave_type_id,
        from_date,
        to_date,
        days_taken,
        session_type,
        status,
        balance_deducted,
        balance_restored,
        reason,
        remarks,
        created_by
    )
    VALUES (?, ?, ?, ?, ?, 'FULL', 'ACTIVE', ?, ?, ?, ?, ?)
");

if (!$saveStmt) {
    throw new RuntimeException(
        "Unable to prepare leave record: " . $conn->error
    );
}

$saveStmt->bind_param(
    "iissdiissi",
    $employeeDbId,
    $leaveTypeId,
    $fromDate,
    $toDate,
    $daysTaken,
    $balanceDeducted,
    $balanceRestored,
    $reason,
    $remarks,
    $createdBy
);

if (!$saveStmt->execute()) {
    throw new RuntimeException(
        "Unable to save leave record: " . $saveStmt->error
    );
}

$saveStmt->close();


        /*
         * A newly entered leave can invalidate EL cycles that were
         * credited earlier. Mark every overlapping cycle as having
         * leave, then reverse each original credit exactly once.
         *
         * The reversal is part of this transaction. If any balance
         * row cannot be updated, the leave entry is rolled back too.
         */
        $markCyclesStmt = $conn->prepare("
            UPDATE el_cycles
            SET leave_occurred = 1
            WHERE employee_id = ?
              AND cycle_start_date <= ?
              AND cycle_end_date >= ?
        ");
        if (!$markCyclesStmt) {
            throw new RuntimeException("Unable to prepare EL cycle update.");
        }
        $markCyclesStmt->bind_param(
            "iss",
            $employeeDbId,
            $toDate,
            $fromDate
        );
        if (!$markCyclesStmt->execute()) {
            throw new RuntimeException("Unable to mark overlapping EL cycles.");
        }
        $markCyclesStmt->close();

        /*
         * Lock credited, not-yet-reversed cycles that overlap this leave.
         */
        $cyclesStmt = $conn->prepare("
            SELECT
                id,
                el_credited,
                COALESCE(credited_year, YEAR(cycle_end_date)) AS credit_year
            FROM el_cycles
            WHERE employee_id = ?
              AND cycle_start_date <= ?
              AND cycle_end_date >= ?
              AND el_credited > 0
              AND el_reversed = 0
            FOR UPDATE
        ");
        if (!$cyclesStmt) {
            throw new RuntimeException("Unable to prepare EL reversal query.");
        }
        $cyclesStmt->bind_param(
            "iss",
            $employeeDbId,
            $toDate,
            $fromDate
        );
        if (!$cyclesStmt->execute()) {
            throw new RuntimeException("Unable to find overlapping EL credits.");
        }
        $cyclesResult = $cyclesStmt->get_result();
        $cyclesToReverse = [];
        while ($cycleRow = $cyclesResult->fetch_assoc()) {
            $cyclesToReverse[] = $cycleRow;
        }
        $cyclesStmt->close();

        if ($cyclesToReverse) {
            $elTypeStmt = $conn->prepare("
                SELECT id
                FROM leave_types
                WHERE code = 'EL'
                LIMIT 1
            ");
            if (!$elTypeStmt) {
                throw new RuntimeException("Unable to prepare EL leave type query.");
            }
            $elTypeStmt->execute();
            $elTypeRow = $elTypeStmt->get_result()->fetch_assoc();
            $elTypeStmt->close();

            if (!$elTypeRow) {
                throw new RuntimeException("EL leave type was not found.");
            }

            $elTypeId = (int)$elTypeRow["id"];

            foreach ($cyclesToReverse as $cycleRow) {
                $cycleId = (int)$cycleRow["id"];
                $creditToReverse = (float)$cycleRow["el_credited"];
                $creditYear = (int)$cycleRow["credit_year"];

                if ($creditToReverse <= 0 || $creditYear <= 0) {
                    throw new RuntimeException(
                        "An overlapping EL cycle has invalid credit details."
                    );
                }

                /*
                 * Always reverse against the year that originally
                 * received this cycle's EL credit, not the leave year.
                 */
                $elBalanceStmt = $conn->prepare("
                    SELECT id, credited, closing_balance
                    FROM leave_balances
                    WHERE employee_id = ?
                      AND leave_type_id = ?
                      AND year = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                if (!$elBalanceStmt) {
                    throw new RuntimeException("Unable to prepare EL balance lookup.");
                }
                $elBalanceStmt->bind_param(
                    "iii",
                    $employeeDbId,
                    $elTypeId,
                    $creditYear
                );
                $elBalanceStmt->execute();
                $elBalance = $elBalanceStmt->get_result()->fetch_assoc();
                $elBalanceStmt->close();

                if (!$elBalance) {
                    throw new RuntimeException(
                        "EL balance record for " . $creditYear .
                        " was not found. No leave was saved."
                    );
                }

                $creditedTotal = (float)$elBalance["credited"];
                if ($creditedTotal + 0.00001 < $creditToReverse) {
                    throw new RuntimeException(
                        "The EL credited total for " . $creditYear .
                        " is lower than the cycle credit. Please check the balance records."
                    );
                }

                $elBalanceId = (int)$elBalance["id"];
                $newCreditedTotal = $creditedTotal - $creditToReverse;
                $newElClosing = (float)$elBalance["closing_balance"] - $creditToReverse;

                $reverseBalanceStmt = $conn->prepare("
                    UPDATE leave_balances
                    SET credited = ?, closing_balance = ?
                    WHERE id = ?
                ");
                if (!$reverseBalanceStmt) {
                    throw new RuntimeException("Unable to prepare EL balance reversal.");
                }
                $reverseBalanceStmt->bind_param(
                    "ddi",
                    $newCreditedTotal,
                    $newElClosing,
                    $elBalanceId
                );
                if (!$reverseBalanceStmt->execute()) {
                    throw new RuntimeException("Unable to reverse the EL balance.");
                }
                $reverseBalanceStmt->close();

                /*
                 * Keep el_credited unchanged for the audit trail.
                 * The flag prevents a second reversal of this cycle.
                 */
                $markReversedStmt = $conn->prepare("
                    UPDATE el_cycles
                    SET el_reversed = 1,
                        reversed_at = NOW()
                    WHERE id = ?
                      AND el_reversed = 0
                ");
                if (!$markReversedStmt) {
                    throw new RuntimeException("Unable to prepare EL reversal audit update.");
                }
                $markReversedStmt->bind_param("i", $cycleId);
                if (!$markReversedStmt->execute() || $markReversedStmt->affected_rows !== 1) {
                    throw new RuntimeException("The EL cycle could not be marked as reversed.");
                }
                $markReversedStmt->close();
            }
        }

        $conn->commit();

        showMessageAndRedirect(
            "Leave record saved successfully. Days recorded: " . $daysTaken . ".",
            $redirectUrl
        );
    } catch (Throwable $e) {
        $conn->rollback();
        showMessageAndRedirect($e->getMessage(), $redirectUrl);
    }
}

/* =========================================================
   LOAD ACTIVE EMPLOYEES
   ========================================================= */
$employees = [];

$employeeStmt = $conn->prepare("
    SELECT employee_id, name, designation, department, gender
    FROM employees
    WHERE status = 'active'
    ORDER BY employee_id ASC
");
$employeeStmt->execute();
$employeeResult = $employeeStmt->get_result();

while ($row = $employeeResult->fetch_assoc()) {
    $employees[$row["employee_id"]] = [
        "name" => $row["name"],
        "designation" => $row["designation"],
        "department" => $row["department"],
        "gender" => $row["gender"],
        "balances" => [
            "CL" => 0,
            "EL" => 0,
            "HPL" => 0
        ]
    ];
}
$employeeStmt->close();

/* =========================================================
   LOAD CURRENT-YEAR BALANCES
   ========================================================= */
$balanceStmt = $conn->prepare("
    SELECT e.employee_id, lt.code, lb.closing_balance
    FROM leave_balances lb
    INNER JOIN employees e ON e.id = lb.employee_id
    INNER JOIN leave_types lt ON lt.id = lb.leave_type_id
    WHERE e.status = 'active'
      AND lb.year = YEAR(CURDATE())
");
$balanceStmt->execute();
$balanceResult = $balanceStmt->get_result();

while ($row = $balanceResult->fetch_assoc()) {
    $code = $row["employee_id"];
    $leaveCode = $row["code"];

    if (isset($employees[$code]["balances"][$leaveCode])) {
        $employees[$code]["balances"][$leaveCode] =
            (float)$row["closing_balance"];
    }
}
$balanceStmt->close();

/* =========================================================
   SELECTED EMPLOYEE AND RETURN LOCATION
   ========================================================= */
$employee_id = trim($_GET["id"] ?? "");
$from = $_GET["from"] ?? "admin";
$selected_employee = $employees[$employee_id] ?? null;

/* Load leave types for the dropdown. */
$leaveTypes = [];
$typeStmt = $conn->prepare("
    SELECT code, name
    FROM leave_types
    WHERE status = 1
    ORDER BY id ASC
");
$typeStmt->execute();
$typeResult = $typeStmt->get_result();

while ($row = $typeResult->fetch_assoc()) {
    $leaveTypes[] = $row;
}
$typeStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Leave - EMMRC</title>
    <link rel="stylesheet" href="../css/style.css?v=2">

    <style>
        .enter-leave-field { position: relative; }

        .custom-date-input {
            width: 100%;
            height: 44px;
            min-height: 44px;
            box-sizing: border-box;
            border: 1px solid #dfe5f0;
            border-radius: 12px;
            background: #f8f9fc;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 15px;
            font-size: 14px;
            color: #777f91;
            cursor: pointer;
            transition: .2s ease;
        }

        .custom-date-input:hover {
            border-color: #526ee8;
            background: #fff;
        }

        .custom-date-input.has-value { color: #26334d; }
        .date-picker-icon { font-size: 16px; }

        .date-picker-popup {
            display: none;
            position: absolute;
            z-index: 99999;
            top: calc(100% + 8px);
            left: 0;
            width: 310px;
            box-sizing: border-box;
            padding: 16px;
            background: #fff;
            border: 1px solid #e1e6f0;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(39,55,90,.15);
        }

        .date-picker-popup.active { display: block; }

        .date-picker-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .date-picker-header strong {
            font-size: 15px;
            color: #26334d;
        }

        .date-picker-nav {
            width: 30px;
            height: 30px;
            padding: 0;
            border: none;
            border-radius: 8px;
            background: #f0f3fb;
            color: #526ee8;
            cursor: pointer;
            font-size: 20px;
            line-height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .date-picker-nav:hover { background: #e6ebfb; }

        .date-picker-weekdays,
        .date-picker-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }

        .date-picker-weekdays { margin-bottom: 6px; }

        .date-picker-weekdays span {
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: #8a93a5;
            padding: 5px 0;
        }

        .date-picker-day {
            font-family: inherit;
            position: relative;
            width: 100%;
            height: 34px;
            padding: 0;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: #30394d;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .date-picker-day:hover { background: #edf1ff; }

        .date-picker-day.disabled {
            color: #c8ccd5;
            background: #f5f6f8;
            cursor: not-allowed;
            opacity: .55;
        }

        .date-picker-day.empty {
            cursor: default;
            background: transparent;
        }

        .date-picker-day.today {
            box-shadow: inset 0 0 0 1.5px #526ee8;
            font-weight: 700;
            color: #526ee8;
        }

        .date-picker-day.today::after {
            content: "";
            position: absolute;
            bottom: 3px;
            left: 50%;
            transform: translateX(-50%);
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #526ee8;
        }

        .date-picker-day.selected {
            background: #526ee8;
            color: #fff;
            font-weight: 700;
        }

        .date-picker-day.selected.today {
            box-shadow: inset 0 0 0 2px #fff;
        }

        .date-picker-day.selected.today::after,
        .date-picker-day.selected .holiday-mark {
            background: #fff;
        }

        .date-picker-day.sunday { color: #e35d6a; }
        .date-picker-day.selected.sunday { background: #526ee8; color: #fff; }

        .date-picker-day.holiday {
            background: #eee7ff;
            color: #5b43a5;
            font-weight: 600;
        }

        .date-picker-day.holiday:hover { background: #e4dcfa; }
        .date-picker-day.selected.holiday { background: #526ee8; color: #fff; }

        .holiday-mark {
            position: absolute;
            bottom: 3px;
            left: 50%;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #7a5bc7;
        }

        .date-picker-holiday-info {
            margin-top: 12px;
            padding: 8px 10px;
            border-radius: 8px;
            background: #f6f3ff;
            color: #5b43a5;
            font-size: 11px;
            line-height: 1.4;
            min-height: 15px;
            box-sizing: border-box;
        }

        .custom-date-input,
        .custom-date-input span,
        .date-picker-popup,
        .date-picker-popup button,
        .date-picker-popup span,
        .date-picker-header strong,
        .date-picker-weekdays span,
        .date-picker-holiday-info {
            font-family: inherit;
        }

        .leave-balance-section {
            margin-top: 18px;
            padding: 18px;
            background: #f8faff;
            border: 1px solid #e2e8f5;
            border-radius: 14px;
        }

        .leave-balance-section > label {
            display: block;
            margin-bottom: 12px;
            color: #26334d;
            font-size: 14px;
            font-weight: 700;
        }

        .leave-balance-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .leave-balance-card {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
            padding: 15px;
            border: 1px solid #e3e8f2;
            border-radius: 12px;
            background: #fff;
        }

        .leave-balance-card span {
            color: #65718a;
            font-size: 12px;
            line-height: 1.4;
        }

        .leave-balance-card strong {
            color: #243653;
            font-size: 21px;
            font-weight: 700;
        }

        .cl-balance { border-top: 3px solid #4f83e5; }
        .el-balance { border-top: 3px solid #39a77a; }
        .hpl-balance { border-top: 3px solid #9b78df; }

        @media (max-width: 650px) {
            .leave-balance-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .date-picker-popup { width: 280px; }
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
    <div class="enter-leave-page-header">
        <div>
            <?php if ($from === "profile" && $employee_id !== ""): ?>
                <a href="employee_profile.php?id=<?php echo urlencode($employee_id); ?>"
                   class="enter-leave-back">← Back to Employee Profile</a>
            <?php else: ?>
                <a href="admin.php" class="enter-leave-back">← Back to Dashboard</a>
            <?php endif; ?>

            <h1>Enter Leave</h1>
            <p>Enter leave details for an employee</p>
        </div>
    </div>

    <div class="enter-leave-card">
        <div class="enter-leave-card-header">
            <div>
                <h2>Leave Details</h2>
                <p>Record employee leave information</p>
            </div>
        </div>

        <form action="" method="POST" id="leaveForm">
            <input type="hidden" name="form_from"
                   value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="enter-leave-section">
                <h3>Employee Information</h3>

                <div class="enter-leave-grid">
                    <div class="enter-leave-field">
                        <label for="employee_id">Employee ID</label>
                        <select name="employee_id" id="employee_id" required
                                onchange="loadEmployee(this.value); filterLeaveTypes();">
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $id => $employee): ?>
                                <option value="<?php echo htmlspecialchars($id); ?>"
                                    <?php echo $id === $employee_id ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($id . " - " . $employee["name"]); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="enter-leave-field">
                        <label for="employee_name">Employee Name</label>
                        <input type="text" id="employee_name"
                               value="<?php echo $selected_employee ? htmlspecialchars($selected_employee["name"]) : ""; ?>"
                               readonly>
                    </div>

                    <div class="enter-leave-field">
                        <label for="designation">Designation</label>
                        <input type="text" id="designation"
                               value="<?php echo $selected_employee ? htmlspecialchars($selected_employee["designation"]) : ""; ?>"
                               readonly>
                    </div>

                    <div class="enter-leave-field">
                        <label for="department">Department</label>
                        <input type="text" id="department"
                               value="<?php echo $selected_employee ? htmlspecialchars($selected_employee["department"]) : ""; ?>"
                               readonly>
                    </div>
                </div>

                <div class="leave-balance-section">
                    <label>Current Leave Balances</label>
                    <div class="leave-balance-grid">
                        <div class="leave-balance-card cl-balance">
                            <span>Casual Leave (CL)</span>
                            <strong id="balance_CL">0 days</strong>
                        </div>
                        <div class="leave-balance-card el-balance">
                            <span>Earned Leave (EL)</span>
                            <strong id="balance_EL">0 days</strong>
                        </div>
                        <div class="leave-balance-card hpl-balance">
                            <span>Half Pay Leave (HPL)</span>
                            <strong id="balance_HPL">0 days</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="enter-leave-section">
                <h3>Leave Information</h3>

                <div class="enter-leave-grid">
                    <div class="enter-leave-field">
                        <label for="leave_type">Leave Type</label>
                        <select name="leave_type" id="leave_type" required>
                            <option value="">Select Leave Type</option>

                           <?php foreach ($leaveTypes as $type): ?>
                             <option
                                value="<?= htmlspecialchars($type['code']) ?>"
                                data-code="<?= htmlspecialchars($type['code']) ?>"
                                >
                                  <?= htmlspecialchars($type['name']) ?>
                               </option>
                           <?php endforeach; ?>
                       </select>
                    </div>

                    <div class="enter-leave-field">
                        <label for="from_date">From Date</label>
                        <input type="hidden" name="from_date" id="from_date">
                        <div class="custom-date-input" onclick="openDatePicker('from')">
                            <span id="from_date_display">dd-mm-yyyy</span>
                            <span class="date-picker-icon">📅</span>
                        </div>
                        <div class="date-picker-popup" id="from_date_picker"></div>
                    </div>

                    <div class="enter-leave-field">
                        <label for="to_date">To Date</label>
                        <input type="hidden" name="to_date" id="to_date">
                        <div class="custom-date-input" onclick="openDatePicker('to')">
                            <span id="to_date_display">dd-mm-yyyy</span>
                            <span class="date-picker-icon">📅</span>
                        </div>
                        <div class="date-picker-popup" id="to_date_picker"></div>
                    </div>

                    <div class="enter-leave-field">
                        <label for="number_of_days">Number of Days</label>
                        <input type="number" name="number_of_days" id="number_of_days"
                               min="1" readonly>
                    </div>
                </div>
            </div>

            <div class="enter-leave-section">
                <h3>Additional Information</h3>

                <div class="enter-leave-field full-width">
                    <label for="reason">Reason</label>
                    <textarea name="reason" id="reason" rows="4"
                              placeholder="Enter reason for leave"></textarea>
                </div>

                <div class="enter-leave-field full-width">
                    <label for="remarks">Remarks</label>
                    <textarea name="remarks" id="remarks" rows="3"
                              placeholder="Enter any additional remarks"></textarea>
                </div>
            </div>

            <div class="enter-leave-notice">
                <span class="notice-icon">ℹ</span>
                <div>
                    <strong>Note</strong>
                    <p>Leave balances are checked and updated when the leave record is saved. Government leave rules that require additional eligibility checks must be configured separately.</p>
                </div>
            </div>

            <div class="enter-leave-buttons">
                <?php if ($from === "profile" && $employee_id !== ""): ?>
                    <a href="employee_profile.php?id=<?php echo urlencode($employee_id); ?>"
                       class="enter-leave-cancel">Cancel</a>
                <?php else: ?>
                    <a href="admin.php" class="enter-leave-cancel">Cancel</a>
                <?php endif; ?>

                <button type="submit" class="enter-leave-save">Save Leave Record</button>
            </div>
        </form>
    </div>
</main>

<script>
const employees = <?php
echo json_encode(
    $employees,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);
?>;

const holidays = <?php
echo json_encode(
    $holidays,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_AMP |
    JSON_HEX_QUOT
);
?>;

let activeDatePicker = null;
let pickerMonth = new Date().getMonth();
let pickerYear = new Date().getFullYear();

function openDatePicker(type) {
    activeDatePicker = type;

    const popup = document.getElementById(type + "_date_picker");
    const existingValue = document.getElementById(type + "_date").value;

    if (existingValue) {
        const selectedDate = new Date(existingValue + "T00:00:00");
        pickerMonth = selectedDate.getMonth();
        pickerYear = selectedDate.getFullYear();
    } else if (type === "to" && document.getElementById("from_date").value) {
        const fromDate = new Date(
            document.getElementById("from_date").value + "T00:00:00"
        );
        pickerMonth = fromDate.getMonth();
        pickerYear = fromDate.getFullYear();
    } else {
        const today = new Date();
        pickerMonth = today.getMonth();
        pickerYear = today.getFullYear();
    }

    document.querySelectorAll(".date-picker-popup").forEach(function(item) {
        item.classList.remove("active");
    });

    renderDatePicker();
    popup.classList.add("active");
}

function closeDatePickers() {
    document.querySelectorAll(".date-picker-popup").forEach(function(item) {
        item.classList.remove("active");
    });
    activeDatePicker = null;
}

function renderDatePicker() {
    if (!activeDatePicker) return;

    const popup = document.getElementById(activeDatePicker + "_date_picker");
    const monthNames = [
        "January", "February", "March", "April", "May", "June",
        "July", "August", "September", "October", "November", "December"
    ];
    const weekdays = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    const firstDay = new Date(pickerYear, pickerMonth, 1).getDay();
    const daysInMonth = new Date(pickerYear, pickerMonth + 1, 0).getDate();
    const selectedValue = document.getElementById(activeDatePicker + "_date").value;
    const fromDateValue = document.getElementById("from_date").value;

    let minimumDate = null;

    if (activeDatePicker === "to" && fromDateValue) {
        minimumDate = new Date(fromDateValue + "T00:00:00");
    }

    const today = new Date();
    const todayString = today.getFullYear() + "-" +
        String(today.getMonth() + 1).padStart(2, "0") + "-" +
        String(today.getDate()).padStart(2, "0");

    let html = `
        <div class="date-picker-header">
            <button type="button" class="date-picker-nav"
                onclick="changePickerMonth(-1);event.stopPropagation()">‹</button>
            <strong>${monthNames[pickerMonth]} ${pickerYear}</strong>
            <button type="button" class="date-picker-nav"
                onclick="changePickerMonth(1);event.stopPropagation()">›</button>
        </div>
        <div class="date-picker-weekdays">
    `;

    weekdays.forEach(function(day) {
        html += `<span>${day}</span>`;
    });

    html += `</div><div class="date-picker-days">`;

    for (let i = 0; i < firstDay; i++) {
        html += `<button type="button" class="date-picker-day empty" disabled></button>`;
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const dateString = pickerYear + "-" +
            String(pickerMonth + 1).padStart(2, "0") + "-" +
            String(day).padStart(2, "0");

        const dateObject = new Date(pickerYear, pickerMonth, day);
        const isSunday = dateObject.getDay() === 0;
        const holiday = holidays.find(item => item.date === dateString);
        const isToday = dateString === todayString;

        const isBeforeFromDate =
            activeDatePicker === "to" &&
            minimumDate &&
            dateObject < minimumDate;

        let classes = "date-picker-day";
        if (isSunday) classes += " sunday";
        if (holiday) classes += " holiday";
        if (isToday) classes += " today";
        if (selectedValue === dateString) classes += " selected";
        if (isBeforeFromDate) classes += " disabled";

        const title = holiday
            ? `title="${String(holiday.description || "").replace(/"/g, "&quot;")}"`
            : "";

        const action = isBeforeFromDate
            ? "disabled"
            : `onclick="selectDate('${dateString}');event.stopPropagation()"`;

        html += `
            <button type="button" class="${classes}" ${action} ${title}>
                ${day}
                ${holiday ? '<span class="holiday-mark"></span>' : ""}
            </button>
        `;
    }

    html += `</div>`;

    const selectedHoliday = holidays.find(item => item.date === selectedValue);

    html += `
        <div class="date-picker-holiday-info">
            ${selectedHoliday
                ? String(selectedHoliday.description || "")
                : "Select a date"}
        </div>
    `;

    popup.innerHTML = html;
}

function changePickerMonth(direction) {
    pickerMonth += direction;

    if (pickerMonth < 0) {
        pickerMonth = 11;
        pickerYear--;
    }

    if (pickerMonth > 11) {
        pickerMonth = 0;
        pickerYear++;
    }

    renderDatePicker();
}

function selectDate(dateString) {
    if (!activeDatePicker) return;

    if (activeDatePicker === "to" &&
        document.getElementById("from_date").value) {
        const fromDate = new Date(
            document.getElementById("from_date").value + "T00:00:00"
        );
        const selectedDate = new Date(dateString + "T00:00:00");

        if (selectedDate < fromDate) {
            alert("To Date cannot be earlier than From Date.");
            return;
        }
    }

    const type = activeDatePicker;
    const hiddenField = document.getElementById(type + "_date");
    const displayField = document.getElementById(type + "_date_display");

    hiddenField.value = dateString;

    const parts = dateString.split("-");
    displayField.textContent = parts[2] + "-" + parts[1] + "-" + parts[0];
    displayField.parentElement.classList.add("has-value");

    if (type === "from") {
        const existingToDate = document.getElementById("to_date").value;

        if (existingToDate && existingToDate < dateString) {
            document.getElementById("to_date").value = "";
            document.getElementById("to_date_display").textContent = "dd-mm-yyyy";
            document.getElementById("to_date_display")
                .parentElement.classList.remove("has-value");
        }
    }

    closeDatePickers();
    calculateDays();
}

document.addEventListener("click", function(event) {
    if (!event.target.closest(".custom-date-input") &&
        !event.target.closest(".date-picker-popup")) {
        closeDatePickers();
    }
});

function loadEmployee(employeeId) {
    const nameField = document.getElementById("employee_name");
    const designationField = document.getElementById("designation");
    const departmentField = document.getElementById("department");
    const selected = employees[employeeId];

    function displayBalance(code, value) {
        const field = document.getElementById("balance_" + code);
        if (!field) return;

        const number = Number(value ?? 0);
        const formatted = Number.isFinite(number)
            ? number.toLocaleString("en-IN", { maximumFractionDigits: 2 })
            : "0";

        field.textContent = formatted + (number === 1 ? " day" : " days");
    }

    if (selected) {
        nameField.value = selected.name || "";
        designationField.value = selected.designation || "";
        departmentField.value = selected.department || "";

        const balances = selected.balances || {};
        displayBalance("CL", balances.CL);
        displayBalance("EL", balances.EL);
        displayBalance("HPL", balances.HPL);
    } else {
        nameField.value = "";
        designationField.value = "";
        departmentField.value = "";
        displayBalance("CL", 0);
        displayBalance("EL", 0);
        displayBalance("HPL", 0);
    }
}

function calculateDays() {
    const fromDate = document.getElementById("from_date").value;
    const toDate = document.getElementById("to_date").value;
    const leaveType = document.getElementById("leave_type").value;
    const daysField = document.getElementById("number_of_days");

    if (!fromDate || !toDate) {
        daysField.value = "";
        return;
    }

    const start = new Date(fromDate + "T00:00:00");
    const end = new Date(toDate + "T00:00:00");

    if (end < start) {
        daysField.value = "";
        alert("To Date cannot be earlier than From Date.");
        return;
    }

    let totalDays = 0;
    const holidayDates = new Set(holidays.map(item => item.date));
    const current = new Date(start);

    while (current <= end) {
        const year = current.getFullYear();
        const month = String(current.getMonth() + 1).padStart(2, "0");
        const day = String(current.getDate()).padStart(2, "0");
        const dateString = year + "-" + month + "-" + day;

        if (leaveType === "CL" && holidayDates.has(dateString)) {
            current.setDate(current.getDate() + 1);
            continue;
        }

        totalDays++;
        current.setDate(current.getDate() + 1);
    }

    daysField.value = totalDays;
}

document.getElementById("leaveForm").addEventListener("submit", function(event) {
    const employeeId = document.getElementById("employee_id").value;
    const leaveType = document.getElementById("leave_type").value;
    const fromDate = document.getElementById("from_date").value;
    const toDate = document.getElementById("to_date").value;

    if (!employeeId || !leaveType || !fromDate || !toDate) {
        event.preventDefault();
        alert("Please complete all required leave details.");
        return;
    }

    if (toDate < fromDate) {
        event.preventDefault();
        alert("To Date cannot be earlier than From Date.");
        return;
    }

    calculateDays();

    if (Number(document.getElementById("number_of_days").value) <= 0) {
        event.preventDefault();
        alert("Please select valid leave dates.");
    }
});

<?php if ($employee_id !== "" && isset($employees[$employee_id])): ?>
loadEmployee(<?php echo json_encode($employee_id); ?>);
<?php endif; ?>

function filterLeaveTypes() {
    const employeeSelect = document.getElementById('employee_id');
    const leaveTypeSelect = document.getElementById('leave_type');

    if (!employeeSelect || !leaveTypeSelect) return;

    const employeeCode = employeeSelect.value;

    // Employee data loaded from PHP
    const employees = <?= json_encode(
        $employees,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    const employee = employees[employeeCode];
    const gender = employee
        ? String(employee.gender || '').trim().toLowerCase()
        : '';

    Array.from(leaveTypeSelect.options).forEach(option => {
        const code = option.dataset.code || option.value;

        let allowed = true;

        if (code === 'ML') {
            allowed = gender === 'female';
        } else if (code === 'PL') {
            allowed = gender === 'male';
        }

        option.hidden = !allowed && option.value !== '';
        option.disabled = !allowed && option.value !== '';

        // Clear a leave type if it is not allowed for this employee
        if (!allowed && leaveTypeSelect.value === option.value) {
            leaveTypeSelect.value = '';
        }
    });

    if (typeof calculateDays === 'function') {
        calculateDays();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    filterLeaveTypes();
});

</script>

</body>
</html>
