
<?php

session_start();

require_once __DIR__ . "/../db.php";

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

    $conn->beginTransaction();

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
        WHERE lr.id = :leave_id
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        ":leave_id" => $leaveId
    ]);

    $leave = $stmt->fetch();

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

    // Use the From Date year
    $year = (int)date("Y", strtotime($leave["from_date"]));

    if ($days <= 0) {
        throw new RuntimeException("Invalid leave duration.");
    }

    // 2. Restore balance only if it was deducted
    // and has not already been restored.

    if ($balanceDeducted === 1 && $balanceRestored === 0) {

        // CL, EL and HPL restore their own balances.
        if (in_array($leaveCode, ["CL", "EL", "HPL"], true)) {

            $restoreStmt = $conn->prepare("
                UPDATE leave_balances
                SET
                    used = used - :days_used,
                    closing_balance = closing_balance + :days_restored
                WHERE employee_id = :employee_id
                  AND leave_type_id = :leave_type_id
                  AND year = :year
                  AND used >= :minimum_used
            ");

            $restoreStmt->execute([
                ":days_used" => $days,
                ":days_restored" => $days,
                ":employee_id" => $employeeId,
                ":leave_type_id" => $leaveTypeId,
                ":year" => $year,
                ":minimum_used" => $days
            ]);

            if ($restoreStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    "The leave balance could not be restored. Please verify the balance row and used days."
                );
            }
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

            $hplStmt->execute();
            $hpl = $hplStmt->fetch();

            if (!$hpl) {
                throw new RuntimeException("HPL leave type not found.");
            }

            $hplTypeId = (int)$hpl["id"];
            $hplDays = $days * 2;

            $restoreHplStmt = $conn->prepare("
                UPDATE leave_balances
                SET
                    used = used - :days_used,
                    closing_balance = closing_balance + :days_restored
                WHERE employee_id = :employee_id
                  AND leave_type_id = :leave_type_id
                  AND year = :year
                  AND used >= :minimum_used
            ");

            $restoreHplStmt->execute([
                ":days_used" => $hplDays,
                ":days_restored" => $hplDays,
                ":employee_id" => $employeeId,
                ":leave_type_id" => $hplTypeId,
                ":year" => $year,
                ":minimum_used" => $hplDays
            ]);

            if ($restoreHplStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    "The HPL balance could not be restored for COM."
                );
            }
        }

        else {
            throw new RuntimeException(
                "Unexpected leave type marked as balance-deducted: " .
                $leaveCode
            );
        }

        // Record that restoration has happened.
        $trackingStmt = $conn->prepare("
            UPDATE leave_records
            SET balance_restored = 1
            WHERE id = :leave_id
              AND balance_deducted = 1
              AND balance_restored = 0
              AND status = 'ACTIVE'
        ");

        $trackingStmt->execute([
            ":leave_id" => $leaveId
        ]);

        if ($trackingStmt->rowCount() !== 1) {
            throw new RuntimeException(
                "Balance restoration tracking could not be updated."
            );
        }
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
            cancelled_by = :cancelled_by
        WHERE id = :leave_id
          AND status = 'ACTIVE'
    ");

    $cancelStmt->execute([
        ":cancelled_by" => $cancelledBy,
        ":leave_id" => $leaveId
    ]);

    if ($cancelStmt->rowCount() !== 1) {
        throw new RuntimeException(
            "Leave cancellation status could not be updated."
        );
    }

    // 4. Find affected EL cycles
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
        WHERE employee_id = :employee_id
          AND cycle_start_date <= :leave_to_date
          AND cycle_end_date >= :leave_from_date
          AND leave_occurred = 1
        FOR UPDATE
    ");

    $cyclesStmt->execute([
        ":employee_id" => $employeeId,
        ":leave_to_date" => $leave["to_date"],
        ":leave_from_date" => $leave["from_date"]
    ]);

    $affectedCycles = $cyclesStmt->fetchAll();

    // Find the EL leave type
    $elTypeStmt = $conn->prepare("
        SELECT id
        FROM leave_types
        WHERE code = 'EL'
        LIMIT 1
    ");

    $elTypeStmt->execute();
    $elType = $elTypeStmt->fetch();

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
            WHERE employee_id = :employee_id
              AND status = 'ACTIVE'
              AND from_date <= :cycle_end
              AND to_date >= :cycle_start
        ");

        $activeLeaveStmt->execute([
            ":employee_id" => $employeeId,
            ":cycle_end" => $cycleEnd,
            ":cycle_start" => $cycleStart
        ]);

        $activeLeaveCount = (int)$activeLeaveStmt->fetch()["total"];

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
            WHERE employee_id = :employee_id
              AND leave_type_id = :leave_type_id
              AND year = :year
            LIMIT 1
            FOR UPDATE
        ");

        $elBalanceStmt->execute([
            ":employee_id" => $employeeId,
            ":leave_type_id" => $elTypeId,
            ":year" => $creditYear
        ]);

        $elBalance = $elBalanceStmt->fetch();

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
                credited = :credited,
                closing_balance = :closing_balance
            WHERE id = :id
        ");

        $updateElStmt->execute([
            ":credited" => $newCredited,
            ":closing_balance" => $newClosing,
            ":id" => $elBalanceId
        ]);

        // Update current cycle credit.
        // Keep el_original_credited unchanged for audit history.
        $updateCycleStmt = $conn->prepare("
            UPDATE el_cycles
            SET
                leave_occurred = 0,
                el_credited = :el_credited,
                el_reversed = 0,
                reversed_at = NULL
            WHERE id = :id
        ");

        $updateCycleStmt->execute([
            ":el_credited" => $allowedCredit,
            ":id" => $cycleId
        ]);
    }

    // 6. All changes succeed together
    $conn->commit();

    // Rotate the cancellation token
    unset($_SESSION["cancel_leave_csrf"]);

    finishCancellation(
        "Leave cancelled successfully. Applicable leave balances have been restored."
    );

} catch (Throwable $e) {

    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log("Leave cancellation error: " . $e->getMessage());

    finishCancellation(
        "Cancellation failed. Please check the database configuration or contact the administrator."
    );
}
