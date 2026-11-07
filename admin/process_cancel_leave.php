
<?php
session_start();
require_once "../db.php";

// Admin access
if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit();
}

// POST only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: leave_records.php");
    exit();
}

// CSRF validation
$submittedToken = $_POST["csrf_token"] ?? "";
$sessionToken = $_SESSION["cancel_leave_csrf"] ?? "";

if (
    $sessionToken === "" ||
    $submittedToken === "" ||
    !hash_equals($sessionToken, $submittedToken)
) {
    die("Invalid security token. Please return and try again.");
}

$leaveId = filter_var(
    $_POST["leave_id"] ?? null,
    FILTER_VALIDATE_INT
);

if (!$leaveId || $leaveId <= 0) {
    die("Invalid leave record ID.");
}

function finishCancellation(string $message): void
{
    echo "<script>alert(" . json_encode($message) . ");";
    echo "window.location.href='leave_records.php';</script>";
    exit();
}

try {
    $conn->begin_transaction();

    // 1. Lock and fetch the leave record
    $stmt = $conn->prepare("
    SELECT
        lr.id,
        lr.employee_id,
        lr.leave_type_id,
        lr.from_date,
        lr.to_date,
        lr.days_taken,
        lr.status,
        lr.balance_deducted,
        lr.balance_restored,
        lt.code AS leave_code
        FROM leave_records lr
        INNER JOIN leave_types lt
            ON lt.id = lr.leave_type_id
        WHERE lr.id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->bind_param("i", $leaveId);
    $stmt->execute();

    $leave = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$leave) {
        throw new RuntimeException("Leave record not found.");
    }

    if ($leave["status"] !== "ACTIVE") {
        throw new RuntimeException(
            "This leave has already been cancelled."
        );
    }

    $employeeId = (int)$leave["employee_id"];
    $leaveTypeId = (int)$leave["leave_type_id"];
    $leaveCode = strtoupper($leave["leave_code"]);
    $days = (float)$leave["days_taken"];
    $balanceDeducted = (int)$leave["balance_deducted"];
    $balanceRestored = (int)$leave["balance_restored"];

    // Match enter_leave.php: use the From Date year
    $year = (int)date("Y", strtotime($leave["from_date"]));

    if ($days <= 0) {
        throw new RuntimeException("Invalid leave duration.");
    }

    // 2. Restore balance only if it was actually deducted
//    and has not already been restored.

if ($balanceDeducted === 1 && $balanceRestored === 0) {

    // CL, EL and HPL restore their own balances.
    if (in_array($leaveCode, ["CL", "EL", "HPL"], true)) {

        $restoreStmt = $conn->prepare("
            UPDATE leave_balances
            SET
                used = used - ?,
                closing_balance = closing_balance + ?
            WHERE employee_id = ?
              AND leave_type_id = ?
              AND year = ?
              AND used >= ?
        ");

        if (!$restoreStmt) {
            throw new RuntimeException(
                "Unable to prepare balance restoration."
            );
        }

        $restoreStmt->bind_param(
            "ddiiid",
            $days,
            $days,
            $employeeId,
            $leaveTypeId,
            $year,
            $days
        );

        if (!$restoreStmt->execute()) {
            throw new RuntimeException(
                "Unable to restore leave balance: " .
                $restoreStmt->error
            );
        }

        if ($restoreStmt->affected_rows !== 1) {
            throw new RuntimeException(
                "The leave balance could not be restored. " .
                "Please verify the balance row and used days."
            );
        }

        $restoreStmt->close();
    }

    // COM restores twice the leave days to HPL.
    elseif ($leaveCode === "COM") {

        $hplStmt = $conn->prepare("
            SELECT id
            FROM leave_types
            WHERE code = 'HPL'
              AND status = 1
            LIMIT 1
        ");

        if (!$hplStmt) {
            throw new RuntimeException(
                "Unable to prepare HPL leave type query."
            );
        }

        $hplStmt->execute();
        $hpl = $hplStmt->get_result()->fetch_assoc();
        $hplStmt->close();

        if (!$hpl) {
            throw new RuntimeException("HPL leave type not found.");
        }

        $hplTypeId = (int)$hpl["id"];
        $hplDays = $days * 2;

        $restoreHplStmt = $conn->prepare("
            UPDATE leave_balances
            SET
                used = used - ?,
                closing_balance = closing_balance + ?
            WHERE employee_id = ?
              AND leave_type_id = ?
              AND year = ?
              AND used >= ?
        ");

        if (!$restoreHplStmt) {
            throw new RuntimeException(
                "Unable to prepare HPL restoration."
            );
        }

        $restoreHplStmt->bind_param(
            "ddiiid",
            $hplDays,
            $hplDays,
            $employeeId,
            $hplTypeId,
            $year,
            $hplDays
        );

        if (!$restoreHplStmt->execute()) {
            throw new RuntimeException(
                "Unable to restore HPL balance: " .
                $restoreHplStmt->error
            );
        }

        if ($restoreHplStmt->affected_rows !== 1) {
            throw new RuntimeException(
                "The HPL balance could not be restored for COM."
            );
        }

        $restoreHplStmt->close();
    }

    else {
        throw new RuntimeException(
            "Unexpected leave type marked as balance-deducted: " .
            $leaveCode
        );
    }

    // Record that the balance restoration has happened.
    $trackingStmt = $conn->prepare("
        UPDATE leave_records
        SET balance_restored = 1
        WHERE id = ?
          AND balance_deducted = 1
          AND balance_restored = 0
          AND status = 'ACTIVE'
    ");

    if (!$trackingStmt) {
        throw new RuntimeException(
            "Unable to prepare balance restoration tracking."
        );
    }

    $trackingStmt->bind_param("i", $leaveId);

    if (!$trackingStmt->execute()) {
        throw new RuntimeException(
            "Unable to update balance restoration tracking."
        );
    }

    if ($trackingStmt->affected_rows !== 1) {
        throw new RuntimeException(
            "Balance restoration tracking could not be updated."
        );
    }

    $trackingStmt->close();
}

// If balance_deducted = 0, no balance is restored.
// This applies to SCL, LWA, ML and PL.
// If balance_restored = 1, restoration is not repeated.


    // 3. Mark the leave as cancelled
    $cancelledBy = (int)($_SESSION["user_id"] ?? 0);

    $cancelStmt = $conn->prepare("
        UPDATE leave_records
        SET
            status = 'CANCELLED',
            cancelled_at = NOW(),
            cancelled_by = ?
        WHERE id = ?
          AND status = 'ACTIVE'
    ");

    $cancelStmt->bind_param(
        "ii",
        $cancelledBy,
        $leaveId
    );

    if (!$cancelStmt->execute()) {
        throw new RuntimeException(
            "Unable to update leave cancellation status."
        );
    }

    if ($cancelStmt->affected_rows !== 1) {
        throw new RuntimeException(
            "Leave cancellation status could not be updated."
        );
    }

    $cancelStmt->close();

    // 4. Find affected EL cycles
    // Only cycles previously marked as having leave are relevant.
    $cyclesStmt = $conn->prepare("
        SELECT
            id,
            cycle_start_date,
            cycle_end_date,
            COALESCE(
                credited_year,
                YEAR(cycle_end_date)
            ) AS credit_year
        FROM el_cycles
        WHERE employee_id = ?
          AND cycle_start_date <= ?
          AND cycle_end_date >= ?
          AND leave_occurred = 1
        FOR UPDATE
    ");

    $cyclesStmt->bind_param(
        "iss",
        $employeeId,
        $leave["to_date"],
        $leave["from_date"]
    );

    $cyclesStmt->execute();
    $cyclesResult = $cyclesStmt->get_result();

    $affectedCycles = [];

    while ($cycle = $cyclesResult->fetch_assoc()) {
        $affectedCycles[] = $cycle;
    }

    $cyclesStmt->close();

    // Find the EL leave type
    $elTypeStmt = $conn->prepare("
        SELECT id
        FROM leave_types
        WHERE code = 'EL'
        LIMIT 1
    ");

    $elTypeStmt->execute();
    $elType = $elTypeStmt->get_result()->fetch_assoc();
    $elTypeStmt->close();

    if ($affectedCycles && !$elType) {
        throw new RuntimeException("EL leave type not found.");
    }

    $elTypeId = $elType ? (int)$elType["id"] : 0;

    // 5. Recalculate each affected cycle
    foreach ($affectedCycles as $cycle) {

        $cycleId = (int)$cycle["id"];
        $cycleStart = $cycle["cycle_start_date"];
        $cycleEnd = $cycle["cycle_end_date"];
        $creditYear = (int)$cycle["credit_year"];

        // Check whether another ACTIVE leave still overlaps this cycle
        $activeLeaveStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM leave_records
            WHERE employee_id = ?
              AND status = 'ACTIVE'
              AND from_date <= ?
              AND to_date >= ?
        ");

        $activeLeaveStmt->bind_param(
            "iss",
            $employeeId,
            $cycleEnd,
            $cycleStart
        );

        $activeLeaveStmt->execute();

        $activeLeaveCount = (int)$activeLeaveStmt
            ->get_result()
            ->fetch_assoc()["total"];

        $activeLeaveStmt->close();

        // Another active leave remains: do not restore EL credit
        if ($activeLeaveCount > 0) {
            continue;
        }

        if ($creditYear <= 0) {
            throw new RuntimeException(
                "Invalid EL credit year for cycle " . $cycleId
            );
        }

        // Lock the EL balance for the original credit year
        $elBalanceStmt = $conn->prepare("
            SELECT id, credited, closing_balance
            FROM leave_balances
            WHERE employee_id = ?
              AND leave_type_id = ?
              AND year = ?
            LIMIT 1
            FOR UPDATE
        ");

        $elBalanceStmt->bind_param(
            "iii",
            $employeeId,
            $elTypeId,
            $creditYear
        );

        $elBalanceStmt->execute();
        $elBalance = $elBalanceStmt
            ->get_result()
            ->fetch_assoc();

        $elBalanceStmt->close();

        if (!$elBalance) {
            throw new RuntimeException(
                "EL balance for " . $creditYear .
                " was not found. Cancellation was not completed."
            );
        }

        // Match the current EL maximum balance of 300 days
        $currentClosing = (float)$elBalance["closing_balance"];
        $currentCredited = (float)$elBalance["credited"];

        $allowedCredit = min(
            1.00,
            max(0.00, 300.00 - $currentClosing)
        );

        $newCredited = $currentCredited + $allowedCredit;
        $newClosing = $currentClosing + $allowedCredit;

        $elBalanceId = (int)$elBalance["id"];

        $updateElStmt = $conn->prepare("
            UPDATE leave_balances
            SET
                credited = ?,
                closing_balance = ?
            WHERE id = ?
        ");

        $updateElStmt->bind_param(
            "ddi",
            $newCredited,
            $newClosing,
            $elBalanceId
        );

        if (!$updateElStmt->execute()) {
            throw new RuntimeException(
                "Unable to recalculate EL balance."
            );
        }

        $updateElStmt->close();

        // Update current cycle credit.
        // Keep el_original_credited unchanged for audit history.
        $updateCycleStmt = $conn->prepare("
            UPDATE el_cycles
            SET
                leave_occurred = 0,
                el_credited = ?,
                el_reversed = 0,
                reversed_at = NULL
            WHERE id = ?
        ");

        $updateCycleStmt->bind_param(
            "di",
            $allowedCredit,
            $cycleId
        );

        if (!$updateCycleStmt->execute()) {
            throw new RuntimeException(
                "Unable to update EL cycle " . $cycleId
            );
        }

        $updateCycleStmt->close();
    }

    // 6. All changes succeed together
    $conn->commit();

    // Rotate the cancellation token
    unset($_SESSION["cancel_leave_csrf"]);

    finishCancellation(
        "Leave cancelled successfully. Applicable leave balances have been restored."
    );

} catch (Throwable $e) {

    $conn->rollback();

    finishCancellation(
        "Cancellation failed: " . $e->getMessage()
    );
}
