
<?php

require_once "db.php";

/*
|--------------------------------------------------------------------------
| TEMPORARY DIAGNOSTIC
|--------------------------------------------------------------------------
| Set to true to display each date counted or excluded.
| When enabled, processing stops before any EL balance changes.
*/
$debugELCycle = false;


/**
 * Check whether a date is eligible for EL accrual.
 *
 * Excludes:
 * - Sundays
 * - Second Saturdays
 * - Manually added holidays (source_type = 'M')
 */
function isEligibleELDutyDay($conn, DateTime $date)
{
    // Exclude Sundays.
    if ($date->format("w") === "0") {
        return false;
    }

    // Exclude the second Saturday of the month.
    if (
        $date->format("N") === "6"
        && (int)$date->format("j") >= 8
        && (int)$date->format("j") <= 14
    ) {
        return false;
    }

    // Exclude manually added holidays only.
    $holidayDate = $date->format("Y-m-d");

    $stmt = $conn->prepare("
        SELECT id
        FROM holidays
        WHERE holiday_date = ?
          AND source_type = 'M'
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Could not prepare holiday query.");
    }

    $stmt->bind_param("s", $holidayDate);
    $stmt->execute();

    $holiday = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !$holiday;
}


/**
 * Process one completed Earned Leave cycle.
 *
 * Rules:
 * - 1 EL for every 11 eligible duty days.
 * - Any leave overlapping the cycle means no EL for that cycle.
 * - Maximum accumulated EL balance is 300 days.
 * - The next cycle starts the day after the previous cycle ends.
 *
 * Late-entered leave is handled by the leave-entry workflow,
 * which reverses any affected credited cycle exactly once.
 */
function processEarnedLeave($conn, $employee_db_id)
{
    global $debugELCycle;

    try {
        /*
         * Get employee details.
         */
        $stmt = $conn->prepare("
            SELECT
                id,
                employee_id,
                name,
                el_cycle_start_date,
                el_cycle_day
            FROM employees
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception("Could not prepare employee query.");
        }

        $stmt->bind_param("i", $employee_db_id);
        $stmt->execute();

        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            return [
                "success" => false,
                "message" => "Employee not found."
            ];
        }

        if (empty($employee["el_cycle_start_date"])) {
            return [
                "success" => false,
                "message" => "EL cycle start date is not configured."
            ];
        }

        /*
         * Calculate the 11th eligible duty day.
         */
        $cycle_start = new DateTime(
            $employee["el_cycle_start_date"]
        );

        $cycle_end = clone $cycle_start;
        $eligible_days = 0;
        $debug_dates = [];

        while ($eligible_days < 11) {
            $date_label = $cycle_end->format("d-m-Y");

            if (isEligibleELDutyDay($conn, $cycle_end)) {
                $eligible_days++;

                $debug_dates[] =
                    $date_label . " = Eligible day " . $eligible_days;
            } else {
                $debug_dates[] = $date_label . " = Excluded";
            }

            if ($eligible_days < 11) {
                $cycle_end->modify("+1 day");
            }
        }

        /*
         * Diagnostic mode: display dates and stop.
         * No balance or cycle records are changed.
         */
        if ($debugELCycle) {
            return [
                "success" => true,
                "diagnostic" => true,
                "cycle_start" => $cycle_start->format("d-m-Y"),
                "cycle_end" => $cycle_end->format("d-m-Y"),
                "eligible_days_required" => 11,
                "dates" => $debug_dates
            ];
        }

        /*
         * The cycle is complete only when its final date
         * has arrived or passed.
         */
        $today = new DateTime("today");

        if ($today < $cycle_end) {
            return [
                "success" => false,
                "message" => "Current EL cycle is not completed yet.",
                "cycle_start" => $cycle_start->format("d-m-Y"),
                "cycle_end" => $cycle_end->format("d-m-Y"),
                "eligible_days_required" => 11
            ];
        }

        $cycle_start_sql = $cycle_start->format("Y-m-d");
        $cycle_end_sql = $cycle_end->format("Y-m-d");

        /*
         * Start transaction before checking and updating
         * the cycle and balance.
         */
        $conn->begin_transaction();

        /*
         * Prevent duplicate processing.
         */
        $stmt = $conn->prepare("
            SELECT id
            FROM el_cycles
            WHERE employee_id = ?
              AND cycle_start_date = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception("Could not prepare cycle query.");
        }

        $stmt->bind_param(
            "is",
            $employee_db_id,
            $cycle_start_sql
        );

        $stmt->execute();
        $existing_cycle = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing_cycle) {
            $conn->rollback();

            return [
                "success" => false,
                "message" => "This EL cycle has already been processed."
            ];
        }

        /*
         * Check whether any leave overlaps the cycle.
         * All leave types are included.
         */
        $stmt = $conn->prepare("
    SELECT COUNT(*) AS leave_count
    FROM leave_records
    WHERE employee_id = ?
      AND status = 'ACTIVE'
      AND from_date <= ?
      AND to_date >= ?
");

        if (!$stmt) {
            throw new Exception("Could not prepare leave query.");
        }

        $stmt->bind_param(
            "iss",
            $employee_db_id,
            $cycle_end_sql,
            $cycle_start_sql
        );

        $stmt->execute();
        $leave_result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $leave_occurred =
            ((int)$leave_result["leave_count"] > 0);

        $el_credit = $leave_occurred ? 0 : 1;

        /*
         * EL credit belongs to the calendar year in which
         * the 11th eligible duty day (cycle_end) occurs.
         */
        $credited_year = (int)$cycle_end->format("Y");

        $stmt = $conn->prepare("
            SELECT
                lb.id,
                lb.opening_balance,
                lb.credited,
                lb.used,
                lb.surrendered,
                lb.closing_balance
            FROM leave_balances lb
            INNER JOIN leave_types lt
                ON lb.leave_type_id = lt.id
            WHERE lb.employee_id = ?
              AND lb.year = ?
              AND lt.code = 'EL'
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            throw new Exception("Could not prepare EL balance query.");
        }

        $stmt->bind_param(
            "ii",
            $employee_db_id,
            $credited_year
        );

        $stmt->execute();
        $balance = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$balance) {
            $conn->rollback();

            return [
                "success" => false,
                "message" =>
                    "EL balance record was not found for cycle completion year " . $credited_year . "."
            ];
        }

        /*
         * Apply the maximum accumulation limit of 300 days.
         */
        $current_balance = (float)$balance["closing_balance"];
        $allowed_credit = 0;

        if ($el_credit > 0 && $current_balance < 300) {
            $allowed_credit = min(
                $el_credit,
                300 - $current_balance
            );
        }

        $new_closing_balance =
            $current_balance + $allowed_credit;

        /*
         * Update the balance only when EL is credited.
         */
        if ($allowed_credit > 0) {
            $balance_id = (int)$balance["id"];

            $stmt = $conn->prepare("
                UPDATE leave_balances
                SET
                    credited = credited + ?,
                    closing_balance = ?
                WHERE id = ?
            ");

            if (!$stmt) {
                throw new Exception("Could not prepare balance update.");
            }

            $stmt->bind_param(
                "ddi",
                $allowed_credit,
                $new_closing_balance,
                $balance_id
            );

            $stmt->execute();
            $stmt->close();
        }

        /*
         * Record the completed cycle.
         */
        $cycle_status = "COMPLETED";
        $processed_at = date("Y-m-d H:i:s");

        $stmt = $conn->prepare("
            INSERT INTO el_cycles
    (employee_id, cycle_start_date, cycle_end_date,
     cycle_status, leave_occurred, el_credited,
     el_original_credited, credited_year, processed_at)
VALUES (?, ?, ?, 'COMPLETED', ?, ?, ?, ?, NOW())
");

        if (!$stmt) {
            throw new Exception("Could not prepare cycle insert.");
        }

        $stmt->bind_param(
    "issiddi",
    $employee_db_id,
    $cycle_start_sql,
    $cycle_end_sql,
    $leave_occurred,
    $allowed_credit,
    $allowed_credit,
    $credited_year
);

        $stmt->execute();
        $stmt->close();

        /*
         * Begin the next cycle the day after this cycle ends.
         */
        $next_cycle_start = clone $cycle_end;
        $next_cycle_start->modify("+1 day");

        $next_cycle_start_sql =
            $next_cycle_start->format("Y-m-d");

        $stmt = $conn->prepare("
            UPDATE employees
            SET
                el_cycle_start_date = ?,
                el_cycle_day = 0
            WHERE id = ?
        ");

        if (!$stmt) {
            throw new Exception("Could not prepare employee update.");
        }

        $stmt->bind_param(
            "si",
            $next_cycle_start_sql,
            $employee_db_id
        );

        $stmt->execute();
        $stmt->close();

        $conn->commit();

        return [
            "success" => true,
            "employee" => $employee["name"],
            "cycle_start" => $cycle_start->format("d-m-Y"),
            "cycle_end" => $cycle_end->format("d-m-Y"),
            "leave_occurred" => $leave_occurred,
            "el_credited" => $allowed_credit,
            "new_balance" => $new_closing_balance,
            "next_cycle_start" =>
                $next_cycle_start->format("d-m-Y")
        ];

    } catch (Throwable $e) {
        if ($conn->errno === 0) {
            // The transaction may already have been rolled back.
        }

        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
            // Ignore rollback errors.
        }

        return [
            "success" => false,
            "message" => $e->getMessage()
        ];
    }
}
