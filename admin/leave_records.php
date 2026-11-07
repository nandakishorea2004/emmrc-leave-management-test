<?php
session_start();
require_once "../db.php";

/* ADMIN ACCESS CHECK */
if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}

/* FILTER VALUES */
$search = trim($_GET["search"] ?? "");
$leave_type = trim($_GET["leave_type"] ?? "");
$department = trim($_GET["department"] ?? "");
$date_filter = trim($_GET["date_filter"] ?? "all");
$from_date = trim($_GET["from_date"] ?? "");
$to_date = trim($_GET["to_date"] ?? "");
$sort = trim($_GET["sort"] ?? "created_at");
$direction = strtoupper(trim($_GET["direction"] ?? "DESC"));

$allowed_date_filters = ["all", "today", "week", "month", "custom"];
if (!in_array($date_filter, $allowed_date_filters, true)) $date_filter = "all";
$allowed_sorts = ["employee", "department", "leave_type", "from_date", "to_date", "days", "created_at"];
if (!in_array($sort, $allowed_sorts, true)) $sort = "created_at";
if (!in_array($direction, ["ASC", "DESC"], true)) $direction = "DESC";

$today = new DateTimeImmutable("today");
$report_from = null;
$report_to = null;
$filter_error = "";
switch ($date_filter) {
    case "today":
        $report_from = $today->format("Y-m-d");
        $report_to = $today->format("Y-m-d");
        break;
    case "week":
        $report_from = $today->modify("monday this week")->format("Y-m-d");
        $report_to = $today->modify("sunday this week")->format("Y-m-d");
        break;
    case "month":
        $report_from = $today->modify("first day of this month")->format("Y-m-d");
        $report_to = $today->modify("last day of this month")->format("Y-m-d");
        break;
    case "custom":
        $valid_from = DateTimeImmutable::createFromFormat("!Y-m-d", $from_date);
        $valid_to = DateTimeImmutable::createFromFormat("!Y-m-d", $to_date);
        if ($valid_from && $valid_to && $valid_from->format("Y-m-d") === $from_date && $valid_to->format("Y-m-d") === $to_date && $from_date <= $to_date) {
            $report_from = $from_date;
            $report_to = $to_date;
        } else {
            $filter_error = "Please select a valid custom start date and end date. The end date must not be earlier than the start date.";
        }
        break;
}

/* LOAD FILTER OPTIONS */
$leave_types = [];
$type_result = $conn->query("SELECT name FROM leave_types WHERE status = 1 ORDER BY id");
if ($type_result) while ($row = $type_result->fetch_assoc()) $leave_types[] = $row["name"];

$departments = [];
$department_result = $conn->query("SELECT DISTINCT department FROM employees WHERE status = 'active' AND department <> '' ORDER BY department ASC");
if ($department_result) while ($row = $department_result->fetch_assoc()) $departments[] = $row["department"];

/* BUILD COMMON LEAVE REPORT FILTERS */
$where = ($filter_error !== "") ? " WHERE 1=0 " : " WHERE 1=1 ";
$params = [];
$bind_types = "";
if ($search !== "") {
    $where .= " AND (e.name LIKE ? OR e.employee_id LIKE ?) ";
    $like = "%" . $search . "%";
    $params[] = $like; $params[] = $like; $bind_types .= "ss";
}
if ($leave_type !== "") {
    $where .= " AND lt.name = ? ";
    $params[] = $leave_type; $bind_types .= "s";
}
if ($department !== "") {
    $where .= " AND e.department = ? ";
    $params[] = $department; $bind_types .= "s";
}
if ($date_filter !== "custom" || $filter_error === "") {
    if ($report_from !== null && $report_to !== null) {
        // A leave record is included if any part of its leave period overlaps the selected dates.
        $where .= " AND lr.from_date <= ? AND lr.to_date >= ? ";
        $params[] = $report_to; $params[] = $report_from; $bind_types .= "ss";
    }
}

$sort_columns = [
    "employee" => "e.name",
    "department" => "e.department",
    "leave_type" => "lt.name",
    "from_date" => "lr.from_date",
    "to_date" => "lr.to_date",
    "days" => "lr.days_taken",
    "created_at" => "lr.created_at"
];
$order_sql = " ORDER BY " . $sort_columns[$sort] . " " . $direction . ", lr.id DESC ";

/* LOAD LEAVE RECORDS */
$leave_records = [];
$records_sql = "SELECT
    lr.id AS record_id,
    e.name AS employee,
    e.employee_id AS employee_code,
    e.department,
    lt.code AS leave_code,
    lt.name AS type,
    lr.from_date,
    lr.to_date,
    lr.days_taken AS days,
    lr.created_at,
    lr.status
FROM leave_records lr
INNER JOIN employees e ON lr.employee_id = e.id
INNER JOIN leave_types lt ON lr.leave_type_id = lt.id" . $where . $order_sql;
$stmt = $conn->prepare($records_sql);
if ($stmt) {
    if ($params) $stmt->bind_param($bind_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $leave_records[] = $row;
    $stmt->close();
}

/* REPORT SUMMARY: computed from the same filtered rows */
$total_records = 0;
$total_days = 0.0;
$employee_set = [];

foreach ($leave_records as $record) {
    if ($record["status"] !== "ACTIVE") {
        continue;
    }

    $total_records++;
    $total_days += (float)$record["days"];
    $employee_set[$record["employee_code"]] = true;
}
$total_employees = count($employee_set);

/* DEPARTMENT-WISE ANALYSIS, USING THE SAME FILTERS */
$department_summary = [];
$summary_sql = "SELECT
    e.department,
    COUNT(lr.id) AS record_count,
    COUNT(DISTINCT e.id) AS employee_count,
    COALESCE(SUM(lr.days_taken),0) AS total_days
FROM leave_records lr
INNER JOIN employees e ON lr.employee_id=e.id
INNER JOIN leave_types lt ON lr.leave_type_id=lt.id"
. $where .
" AND lr.status = 'ACTIVE'
GROUP BY e.department
ORDER BY total_days DESC, e.department ASC";
$summary_stmt = $conn->prepare($summary_sql);
if ($summary_stmt) {
    if ($params) $summary_stmt->bind_param($bind_types, ...$params);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    while ($row = $summary_result->fetch_assoc()) $department_summary[] = $row;
    $summary_stmt->close();
}

/* LOAD EL SURRENDER DETAILS (SEARCH AND DEPARTMENT FILTER APPLY) */
$surrender_records = [];
$surrender_where = ($filter_error !== "") ? " WHERE 1=0 " : " WHERE 1=1 ";
$surrender_params = [];
$surrender_types = "";
if ($search !== "") {
    $surrender_where .= " AND (e.name LIKE ? OR e.employee_id LIKE ?) ";
    $like = "%" . $search . "%";
    $surrender_params[] = $like; $surrender_params[] = $like; $surrender_types .= "ss";
}
if ($department !== "") {
    $surrender_where .= " AND e.department = ? ";
    $surrender_params[] = $department; $surrender_types .= "s";
}
if ($report_from !== null && $report_to !== null && ($date_filter !== "custom" || $filter_error === "")) {
    $surrender_where .= " AND es.surrender_date BETWEEN ? AND ? ";
    $surrender_params[] = $report_from; $surrender_params[] = $report_to; $surrender_types .= "ss";
}
$surrender_sql = "SELECT es.id, es.employee_id, e.employee_id AS employee_code, e.name AS employee_name, e.designation, e.department, es.surrender_date, es.year, es.days, es.status, es.remarks FROM el_surrender es INNER JOIN employees e ON es.employee_id=e.id" . $surrender_where . " ORDER BY es.surrender_date DESC, es.id DESC";
$surrender_stmt = $conn->prepare($surrender_sql);
if ($surrender_stmt) {
    if ($surrender_params) $surrender_stmt->bind_param($surrender_types, ...$surrender_params);
    $surrender_stmt->execute();
    $surrender_result = $surrender_stmt->get_result();
    while ($row = $surrender_result->fetch_assoc()) $surrender_records[] = $row;
    $surrender_stmt->close();
}

function getLeaveBadgeClass($leaveType) {
    switch ($leaveType) {
        case "Earned Leave": return "earned-badge";
        case "Half Pay Leave": return "halfpay-badge";
        case "Commuted Leave": return "commuted-badge";
        case "Special Casual Leave": return "special-badge";
        case "Leave Without Allowance": return "lwa-badge";
        default: return "casual-badge";
    }
}
function getSurrenderStatusClass($status) {
    switch (strtoupper($status)) {
        case "APPROVED": return "status-approved";
        case "REJECTED": return "status-rejected";
        default: return "status-pending";
    }
}
function reportUrl(array $changes = []) {
    $values = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null) unset($values[$key]); else $values[$key] = $value;
    }
    return "?" . http_build_query($values);
}
function sortLink($label, $key, $currentSort, $currentDirection) {
    $nextDirection = ($currentSort === $key && strtoupper($currentDirection) === "ASC") ? "DESC" : "ASC";
    $arrow = $currentSort === $key ? (strtoupper($currentDirection) === "ASC" ? " ▲" : " ▼") : "";
    return '<a class="sort-link" href="' . htmlspecialchars(reportUrl(["sort" => $key, "direction" => $nextDirection]), ENT_QUOTES, "UTF-8") . '">' . htmlspecialchars($label, ENT_QUOTES, "UTF-8") . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Reports | EMMRC</title>
<link rel="stylesheet" href="../css/style.css?v=2">
<style>
.records-page{min-height:100vh;height:auto;background:#f6f8fc;overflow:visible}.records-container{width:92%;max-width:1350px;margin:30px auto 50px}.records-back{display:inline-block;margin-bottom:20px;color:#526ee8;text-decoration:none;font-size:13px;font-weight:600}.records-back:hover{text-decoration:none}.records-title{margin-bottom:25px}.records-title h1{margin:0;color:#263b60;font-size:24px}.records-title p{margin:7px 0 0;color:#7b8799;font-size:13px}.records-card{background:#fff;border-radius:12px;padding:25px;margin-bottom:25px;box-shadow:0 4px 15px rgba(30,50,90,.07)}.records-section-title{margin-bottom:20px}.records-section-title h2{margin:0;color:#263b60;font-size:18px}.records-section-title p{margin:6px 0 0;color:#7b8799;font-size:13px}.records-filters{display:grid;grid-template-columns:minmax(180px,1.5fr) minmax(150px,1fr) minmax(150px,1fr) minmax(150px,1fr) auto;gap:12px;margin-bottom:15px;align-items:end}.filter-field{display:flex;flex-direction:column;gap:6px}.filter-field label{font-size:11px;font-weight:700;color:#59729b}.records-filters input,.records-filters select{width:100%;height:40px;border:1px solid #dce2ec;border-radius:7px;padding:0 12px;background:#fff;color:#425574;font-family:inherit;font-size:13px;outline:none;box-sizing:border-box}.records-filters input:focus,.records-filters select:focus{border-color:#526ee8}.filter-button,.reset-button{height:40px;border:0;border-radius:7px;padding:0 18px;background:#526ee8;color:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}.filter-button:hover{background:#435ed4}.reset-button{background:#eef1f7;color:#52627b}.filter-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px}.custom-dates{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}.filter-note{font-size:12px;color:#7b8799;margin:0 0 14px}.filter-error{padding:10px 12px;background:#fff0f0;border:1px solid #f5caca;color:#b42318;border-radius:7px;font-size:13px;margin-bottom:15px}.summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px;margin-bottom:25px}.summary-card{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 4px 15px rgba(30,50,90,.06);border-left:4px solid #526ee8}.summary-card:nth-child(2){border-left-color:#23a86c}.summary-card:nth-child(3){border-left-color:#7545d8}.summary-label{font-size:12px;color:#7b8799;margin-bottom:8px}.summary-value{font-size:26px;font-weight:700;color:#263b60}.summary-unit{font-size:12px;color:#7b8799;margin-left:5px}.records-table-wrapper{overflow-x:auto}.records-table{width:100%;border-collapse:collapse}.records-table th{padding:13px 12px;background:#f6f8fc;text-align:left;color:#59729b;font-size:11px;font-weight:700;white-space:nowrap}.records-table td{padding:14px 12px;border-bottom:1px solid #edf0f5;color:#425574;font-size:13px;white-space:nowrap}.employee-name{color:#263b60;font-weight:600}.sort-link{color:inherit;text-decoration:none}.sort-link:hover{color:#526ee8}.leave-badge{display:inline-block;padding:5px 9px;border-radius:6px;font-size:11px;font-weight:600}.earned-badge{background:#dff5e9;color:#23a86c}.casual-badge{background:#dceaff;color:#105cdf}.halfpay-badge{background:#eee7ff;color:#7545d8}.commuted-badge{background:#fff0d5;color:#d7830f}.special-badge{background:#fce7f3;color:#be185d}.lwa-badge{background:#e5e7eb;color:#4b5563}.record-view-button{min-width:54px;height:32px;padding:0 12px;border:1px solid #526ee8;border-radius:6px;background:#fff;color:#526ee8;font-family:inherit;font-size:12px;font-weight:500;cursor:pointer}.record-view-button:hover{background:#526ee8;color:#fff}.surrender-status{display:inline-block;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:600}.status-pending{background:#fff0d5;color:#c97800}.status-approved{background:#dff5e9;color:#208b58}.status-rejected{background:#fde2e2;color:#c63b3b}.surrender-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}.surrender-count{min-width:32px;height:32px;padding:0 10px;border-radius:16px;background:#f0e9ff;color:#7048c8;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}.no-records{text-align:center;padding:35px;color:#7b8799;font-size:13px}.department-table{margin-bottom:25px}.department-name{font-weight:600;color:#263b60}.table-subtitle{font-size:12px;color:#7b8799;margin-top:5px}@media(max-width:1050px){.records-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.summary-grid{grid-template-columns:1fr 1fr 1fr}}@media(max-width:800px){.records-filters,.filter-row,.custom-dates{grid-template-columns:1fr}.summary-grid{grid-template-columns:1fr}.surrender-card-header{align-items:flex-start;gap:10px}}
</style>

    <link rel="stylesheet" href="../css/responsive.css?v=1">
</head>
<body class="admin-page">
<header class="admin-header">
    <div class="admin-brand">
        <div class="admin-logo"><img src="../assets/emmrc logo.png" alt="EMMRC Logo"></div>
        <div class="admin-brand-divider"></div>
        <div class="admin-brand-text"><strong>Leave Management</strong><span>Admin Portal</span></div>
    </div>
    <div class="admin-user">
        <div class="admin-avatar">A</div>
        <div class="admin-user-details"><strong>Admin</strong><span>Administrator</span></div>
        <a href="../login.php?action=logout" class="admin-logout">↪ Logout</a>
    </div>
</header>
<div class="records-page">
<div class="records-container">
    <a href="admin.php" class="records-back">← Back to Dashboard</a>
    <div class="records-title"><h1>Admin Leave Reports</h1><p>Filter leave records by date and department, review summaries, and sort report results.</p></div>

    <div class="records-card">
        <div class="records-section-title"><h2>Report Filters</h2><p>Choose a reporting period and optionally filter by employee, leave type, or department.</p></div>
        <?php if ($filter_error !== ""): ?><div class="filter-error"><?php echo htmlspecialchars($filter_error); ?></div><?php endif; ?>
        <form method="GET" class="records-filters" id="reportFilterForm">
            <div class="filter-field"><label for="search">Employee</label><input id="search" type="text" name="search" placeholder="Search employee name or ID" value="<?php echo htmlspecialchars($search); ?>"></div>
            <div class="filter-field"><label for="leave_type">Leave Type</label><select id="leave_type" name="leave_type"><option value="">All Leave Types</option><?php foreach ($leave_types as $type): ?><option value="<?php echo htmlspecialchars($type); ?>" <?php echo $leave_type === $type ? "selected" : ""; ?>><?php echo htmlspecialchars($type); ?></option><?php endforeach; ?></select></div>
            <div class="filter-field"><label for="department">Department</label><select id="department" name="department"><option value="">All Departments</option><?php foreach ($departments as $dept): ?><option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department === $dept ? "selected" : ""; ?>><?php echo htmlspecialchars($dept); ?></option><?php endforeach; ?></select></div>
            <div class="filter-field"><label for="date_filter">Date Period</label><select id="date_filter" name="date_filter"><option value="all" <?php echo $date_filter === "all" ? "selected" : ""; ?>>All Dates</option><option value="today" <?php echo $date_filter === "today" ? "selected" : ""; ?>>Today</option><option value="week" <?php echo $date_filter === "week" ? "selected" : ""; ?>>This Week</option><option value="month" <?php echo $date_filter === "month" ? "selected" : ""; ?>>This Month</option><option value="custom" <?php echo $date_filter === "custom" ? "selected" : ""; ?>>Custom Date Range</option></select></div>
            <div style="display:flex;gap:8px;align-items:end"><button type="submit" class="filter-button">Apply Filters</button><a class="reset-button" href="leave_records.php">Reset</a></div>
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>"><input type="hidden" name="direction" value="<?php echo htmlspecialchars($direction); ?>">
        </form>
        <div id="customDateFields" class="custom-dates" style="<?php echo $date_filter === "custom" ? "" : "display:none;"; ?>">
            <div class="filter-field"><label for="from_date">From Date</label><input form="reportFilterForm" type="date" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" <?php echo $date_filter === "custom" ? "required" : ""; ?>></div>
            <div class="filter-field"><label for="to_date">To Date</label><input form="reportFilterForm" type="date" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" <?php echo $date_filter === "custom" ? "required" : ""; ?>></div>
        </div>
        <?php if ($report_from && $report_to): ?><p class="filter-note">Showing leave periods overlapping <strong><?php echo date("d-m-Y", strtotime($report_from)); ?></strong> to <strong><?php echo date("d-m-Y", strtotime($report_to)); ?></strong>.</p><?php else: ?><p class="filter-note">Showing records across all dates<?php echo $department !== "" ? " for " . htmlspecialchars($department) : ""; ?>.</p><?php endif; ?>
    </div>

    <div class="summary-grid">
        <div class="summary-card"><div class="summary-label">Leave Records</div><div class="summary-value"><?php echo number_format($total_records); ?></div></div>
        <div class="summary-card"><div class="summary-label">Employees With Records</div><div class="summary-value"><?php echo number_format($total_employees); ?></div></div>
        <div class="summary-card"><div class="summary-label">Total Leave Days</div><div class="summary-value"><?php echo number_format($total_days, 2); ?><span class="summary-unit">days</span></div></div>
    </div>

    <div class="records-card department-table">
        <div class="records-section-title"><h2>Department-wise Analysis</h2><p>Leave record counts and total days for each department under the selected filters.</p></div>
        <div class="records-table-wrapper"><table class="records-table"><thead><tr><th>Department</th><th>Leave Records</th><th>Employees</th><th>Total Leave Days</th></tr></thead><tbody>
        <?php if ($department_summary): foreach ($department_summary as $dept_row): ?><tr><td class="department-name"><?php echo htmlspecialchars($dept_row["department"] ?: "Unassigned"); ?></td><td><?php echo number_format((int)$dept_row["record_count"]); ?></td><td><?php echo number_format((int)$dept_row["employee_count"]); ?></td><td><?php echo number_format((float)$dept_row["total_days"], 2); ?></td></tr><?php endforeach; else: ?><tr><td colspan="4" class="no-records">No department data found for the selected filters.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>

    <div class="records-card">
        <div class="records-section-title"><h2>Leave Records</h2><p>Click a column heading to sort the report. Latest entered records are shown first by default.</p></div>
        <div class="records-table-wrapper"><table class="records-table"><thead><tr>
            <th><?php echo sortLink("Employee", "employee", $sort, $direction); ?></th>
            <th>Employee ID</th><th><?php echo sortLink("Department", "department", $sort, $direction); ?></th>
            <th><?php echo sortLink("Leave Type", "leave_type", $sort, $direction); ?></th>
            <th><?php echo sortLink("From", "from_date", $sort, $direction); ?></th>
            <th><?php echo sortLink("To", "to_date", $sort, $direction); ?></th>
            <th><?php echo sortLink("Days", "days", $sort, $direction); ?></th>
            <th><?php echo sortLink("Entered", "created_at", $sort, $direction); ?></th>
<th>Status</th>
<th>Action</th>
        </tr></thead><tbody>
        <?php if ($leave_records): foreach ($leave_records as $record): ?><tr>
            <td class="employee-name"><?php echo htmlspecialchars($record["employee"]); ?></td>
            <td><?php echo htmlspecialchars($record["employee_code"]); ?></td>
            <td><?php echo htmlspecialchars($record["department"]); ?></td>
            <td><span class="leave-badge <?php echo getLeaveBadgeClass($record["type"]); ?>"><?php echo htmlspecialchars($record["type"]); ?></span></td>
            <td><?php echo date("d-m-Y", strtotime($record["from_date"])); ?></td>
            <td><?php echo date("d-m-Y", strtotime($record["to_date"])); ?></td>
            <td><?php echo htmlspecialchars((string)$record["days"]); ?></td>
            <td><?php echo date("d-m-Y h:i A", strtotime($record["created_at"])); ?></td>
            <td>
    <span class="surrender-status <?php echo $record["status"] === "ACTIVE" ? "status-approved" : "status-rejected"; ?>">
        <?php echo htmlspecialchars($record["status"]); ?>
    </span>
</td>

<td>
    <button type="button" class="record-view-button"
        onclick="window.location.href='../leave_history.php?id=<?php echo urlencode($record["employee_code"]); ?>&from=admin';">
        View
    </button>

    <?php if ($record["status"] === "ACTIVE"): ?>
        <button type="button" class="record-view-button"
            onclick="window.location.href='cancel_leave.php?id=<?php echo (int)$record["record_id"]; ?>';">
            Cancel
        </button>
    <?php endif; ?>
</td>
        </tr><?php endforeach; else: ?><tr><td colspan="9" class="no-records">No leave records found for the selected filters.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>

    <div class="records-card">
        <div class="surrender-card-header"><div class="records-section-title" style="margin-bottom:0"><h2>EL Surrender Details</h2><p>Latest EL surrender requests and their status, filtered by employee, department and selected dates.</p></div><span class="surrender-count"><?php echo count($surrender_records); ?></span></div>
        <div class="records-table-wrapper"><table class="records-table"><thead><tr><th>Employee</th><th>Employee ID</th><th>Department</th><th>Surrender Date</th><th>Year</th><th>Days</th><th>Status</th><th>Remarks</th><th>Action</th></tr></thead><tbody>
        <?php if ($surrender_records): foreach ($surrender_records as $surrender): ?><tr>
            <td class="employee-name"><?php echo htmlspecialchars($surrender["employee_name"]); ?></td><td><?php echo htmlspecialchars($surrender["employee_code"]); ?></td><td><?php echo htmlspecialchars($surrender["department"]); ?></td>
            <td><?php echo date("d-m-Y", strtotime($surrender["surrender_date"])); ?></td><td><?php echo htmlspecialchars((string)$surrender["year"]); ?></td><td><?php echo htmlspecialchars((string)$surrender["days"]); ?></td>
            <td><span class="surrender-status <?php echo getSurrenderStatusClass($surrender["status"]); ?>"><?php echo htmlspecialchars(ucfirst(strtolower($surrender["status"]))); ?></span></td>
            <td><?php echo trim($surrender["remarks"] ?? "") === "" ? "-" : htmlspecialchars($surrender["remarks"]); ?></td>
            <td><button type="button" class="record-view-button" onclick="window.location.href='../el_surrender.php?id=<?php echo urlencode($surrender["employee_code"]); ?>&from=profile';">View</button></td>
        </tr><?php endforeach; else: ?><tr><td colspan="9" class="no-records">No EL surrender records found for the selected filters.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</div>
</div>
<script>
document.getElementById('date_filter').addEventListener('change', function () {
    const customFields = document.getElementById('customDateFields');
    const isCustom = this.value === 'custom';
    customFields.style.display = isCustom ? 'grid' : 'none';
    document.getElementById('from_date').required = isCustom;
    document.getElementById('to_date').required = isCustom;
});
document.getElementById('from_date').addEventListener('change', function () {
    document.getElementById('to_date').min = this.value;
});
</script>
</body>
</html>
