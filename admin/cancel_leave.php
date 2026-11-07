
<?php
session_start();

require_once "../db.php";

// Admin access check
if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit();
}

// Validate leave record ID
if (!isset($_GET["id"]) || !filter_var($_GET["id"], FILTER_VALIDATE_INT)) {
    die("Invalid leave record ID.");
}

$leaveId = (int) $_GET["id"];

// Fetch leave details
$stmt = $conn->prepare("
    SELECT
        lr.id,
        e.name AS employee_name,
        e.employee_id AS employee_code,
        lt.code AS leave_code,
        lt.name AS leave_name,
        lr.from_date,
        lr.to_date,
        lr.days_taken,
        lr.status
    FROM leave_records lr
    INNER JOIN employees e ON e.id = lr.employee_id
    INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
    WHERE lr.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $leaveId);
$stmt->execute();

$result = $stmt->get_result();
$leave = $result->fetch_assoc();
$stmt->close();

if (!$leave) {
    die("Leave record not found.");
}

if (empty($_SESSION["cancel_leave_csrf"])) {
    $_SESSION["cancel_leave_csrf"] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Cancel Leave | Leave Management</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f3f6fb;
            color: #263445;
            min-height: 100vh;
            padding: 35px 16px;
        }

        .container {
            max-width: 650px;
            margin: 0 auto;
        }

        .header {
            margin-bottom: 22px;
        }

        .header h2 {
            font-size: 25px;
            margin-bottom: 7px;
            color: #1e293b;
        }

        .header p {
            color: #64748b;
            font-size: 14px;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5eaf1;
        }

        .warning-icon {
            width: 52px;
            height: 52px;
            background: #fff1f0;
            color: #dc2626;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
            margin-bottom: 17px;
        }

        .card h3 {
            font-size: 19px;
            margin-bottom: 8px;
        }

        .description {
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 23px;
        }

        .details {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            padding: 18px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 11px 0;
            border-bottom: 1px solid #e8edf3;
            font-size: 14px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #64748b;
        }

        .detail-value {
            font-weight: 600;
            text-align: right;
            color: #1e293b;
        }

        .status {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .active {
            color: #15803d;
            background: #dcfce7;
        }

        .cancelled {
            color: #b91c1c;
            background: #fee2e2;
        }

        .notice {
            margin-top: 20px;
            padding: 13px 15px;
            background: #fff7ed;
            border-left: 4px solid #f59e0b;
            border-radius: 5px;
            color: #9a3412;
            font-size: 13px;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 12px 19px;
            border: none;
            border-radius: 7px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            text-align: center;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .btn-secondary {
            background: #e8edf4;
            color: #334155;
        }

        .btn-secondary:hover {
            background: #dbe3ed;
        }

        .already-cancelled {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 14px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 14px;
        }

        @media (max-width: 480px) {
            .card {
                padding: 20px;
            }

            .detail-row {
                flex-direction: column;
                gap: 5px;
            }

            .detail-value {
                text-align: left;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>

    <link rel="stylesheet" href="../css/responsive.css?v=1">
</head>

<body>

<div class="container">

    <div class="header">
        <h2>Cancel Leave Record</h2>
        <p>Review the leave details before confirming cancellation.</p>
    </div>

    <div class="card">

        <div class="warning-icon">!</div>

        <h3>Leave Cancellation</h3>

        <p class="description">
            Please verify the employee and leave information below.
        </p>

        <div class="details">

            <div class="detail-row">
                <span class="detail-label">Employee Name</span>
                <span class="detail-value">
                    <?= htmlspecialchars($leave["employee_name"]) ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Employee ID</span>
                <span class="detail-value">
                    <?= htmlspecialchars($leave["employee_code"]) ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Leave Type</span>
                <span class="detail-value">
                    <?= htmlspecialchars($leave["leave_code"] . " - " . $leave["leave_name"]) ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">From Date</span>
                <span class="detail-value">
                    <?= htmlspecialchars($leave["from_date"]) ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">To Date</span>
                <span class="detail-value">
                    <?= htmlspecialchars($leave["to_date"]) ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Total Days</span>
                <span class="detail-value">
                    <?= htmlspecialchars($leave["days_taken"]) ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Current Status</span>
                <span class="detail-value">
                    <span class="status <?= $leave["status"] === "ACTIVE" ? "active" : "cancelled" ?>">
                        <?= htmlspecialchars($leave["status"]) ?>
                    </span>
                </span>
            </div>

        </div>

        <?php if ($leave["status"] === "CANCELLED"): ?>

            <div class="already-cancelled">
                This leave record has already been cancelled.
            </div>

        <?php else: ?>

            <div class="notice">
                <strong>Important:</strong>
                Cancelling this leave will restore the applicable leave balance.
                Any related Earned Leave cycle will also be recalculated where applicable.
            </div>

            <form method="POST" action="process_cancel_leave.php">

                <input type="hidden" name="leave_id"
                       value="<?= $leaveId ?>">

                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION["cancel_leave_csrf"]) ?>">

                <div class="actions">

                    <button type="submit" class="btn btn-danger"
                            onclick="return confirm('Are you sure you want to cancel this leave record?');">
                        Confirm Cancellation
                    </button>

                    <a href="leave_records.php" class="btn btn-secondary">
                        Go Back
                    </a>

                </div>

            </form>

        <?php endif; ?>

        <?php if ($leave["status"] === "CANCELLED"): ?>
            <div class="actions">
                <a href="leave_records.php" class="btn btn-secondary">
                    Back to Leave Records
                </a>
            </div>
        <?php endif; ?>

    </div>

</div>

</body>
</html>
