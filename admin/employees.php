
<?php

session_start();

require_once __DIR__ . "/../db.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}

/* =========================================================
   FETCH EMPLOYEES FROM DATABASE USING PDO
========================================================= */

$employees = [];

$sql = "
    SELECT
        employee_id AS id,
        name,
        designation,
        department,
        joining_date,
        status,
        profile_picture
    FROM employees
    ORDER BY name ASC
";

$stmt = $conn->prepare($sql);
$stmt->execute();

$employees = $stmt->fetchAll();

/* =========================================================
   SEARCH & FILTER VALUES
========================================================= */

$search = trim($_GET["search"] ?? "");
$department_filter = trim($_GET["department"] ?? "");
$designation_filter = trim($_GET["designation"] ?? "");

/* =========================================================
   FILTER EMPLOYEES
========================================================= */

$filtered_employees = [];

foreach ($employees as $employee) {

    $matches_search = true;
    $matches_department = true;
    $matches_designation = true;

    if ($search !== "") {

        $search_lower = strtolower($search);

        $matches_search =
            strpos(strtolower((string)$employee["id"]), $search_lower) !== false ||
            strpos(strtolower($employee["name"]), $search_lower) !== false;
    }

    if ($department_filter !== "") {
        $matches_department =
            $employee["department"] === $department_filter;
    }

    if ($designation_filter !== "") {
        $matches_designation =
            $employee["designation"] === $designation_filter;
    }

    if (
        $matches_search &&
        $matches_department &&
        $matches_designation
    ) {
        $filtered_employees[] = $employee;
    }
}

/* =========================================================
   FILTER OPTIONS
========================================================= */

$departments = [];
$designations = [];

foreach ($employees as $employee) {

    $departments[] = $employee["department"];
    $designations[] = $employee["designation"];
}

$departments = array_values(array_unique($departments));
$designations = array_values(array_unique($designations));

sort($departments);
sort($designations);

/* =========================================================
   ADMIN DETAILS
========================================================= */

$admin_name = "Admin";

?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Employee Management | EMMRC</title>

    <link rel="stylesheet" href="../css/style.css?v=3">

    <style>
        /* =====================================================
           PAGE ACTIONS
           ===================================================== */

        .employee-page-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }

        .add-employee-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 10px;
            background: #315eea;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            border: none;
            transition: background 0.2s ease, transform 0.2s ease;
            white-space: nowrap;
        }

        .add-employee-button:hover {
            background: #2449c7;
            transform: translateY(-1px);
        }

        /* =====================================================
           EMPLOYEE PROFILE PHOTO
           ===================================================== */

        .employee-name-cell {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 180px;
        }

        .employee-avatar-photo,
        .employee-avatar-initial {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: 50%;
            overflow: hidden;
            box-sizing: border-box;
        }

        .employee-avatar-photo {
            display: block;
            object-fit: cover;
            object-position: center;
            background: #e8eefb;
        }

        .employee-avatar-initial {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8eefb;
            color: #315eea;
            font-size: 16px;
            font-weight: 700;
        }

        .employee-name-cell > div:last-child {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        .employee-name-cell > div:last-child strong {
            overflow-wrap: anywhere;
        }

        .employee-name-cell > div:last-child span {
            color: #718096;
            font-size: 12px;
        }

        @media (max-width: 600px) {
            .employee-page-actions {
                align-items: stretch;
            }

            .add-employee-button {
                width: 100%;
            }
        }
    </style>

    <link rel="stylesheet" href="../css/responsive.css?v=1">
</head>

<body>

<div class="admin-page">

    <!-- ADMIN HEADER -->

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

            <div class="admin-avatar">A</div>

            <div class="admin-user-details">
                <strong>
                    <?php echo htmlspecialchars($admin_name); ?>
                </strong>

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

    <!-- MAIN CONTAINER -->

    <main class="admin-container">

        <!-- PAGE HEADER -->

        <section class="employee-page-header">

            <div class="employee-page-actions">

                <div>
                    <a href="admin.php" class="back-link">
                        ← Back to Dashboard
                    </a>

                    <h1>Employee Management</h1>

                    <p>
                        View and manage employee profiles and leave accounts.
                    </p>
                </div>

                <a
                    href="add_employee.php"
                    class="add-employee-button"
                >
                    <span aria-hidden="true">＋</span>
                    Add Employee
                </a>

            </div>

        </section>

        <!-- SEARCH & FILTER -->

        <section class="employee-search-card">

            <div class="employee-search-heading">
                <div>
                    <h2>Search Employee</h2>
                    <p>Search by Employee ID or employee name.</p>
                </div>
            </div>

            <form
                method="GET"
                action="employees.php"
                class="employee-search-form"
            >

                <div class="employee-search-input">
                    <label for="employee-search">
                        Employee ID / Name
                    </label>

                    <input
                        id="employee-search"
                        type="text"
                        name="search"
                        placeholder="Enter Employee ID or name..."
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                </div>

                <div class="employee-filter">
                    <label for="department">Department</label>

                    <select id="department" name="department">
                        <option value="">All Departments</option>

                        <?php foreach ($departments as $department): ?>
                            <option
                                value="<?php echo htmlspecialchars($department); ?>"
                                <?php echo $department_filter === $department ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars($department); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="employee-filter">
                    <label for="designation">Designation</label>

                    <select id="designation" name="designation">
                        <option value="">All Designations</option>

                        <?php foreach ($designations as $designation): ?>
                            <option
                                value="<?php echo htmlspecialchars($designation); ?>"
                                <?php echo $designation_filter === $designation ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars($designation); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button
                    type="submit"
                    class="employee-search-button"
                >
                    Search
                </button>

                <?php if (
                    $search !== "" ||
                    $department_filter !== "" ||
                    $designation_filter !== ""
                ): ?>
                    <a
                        href="employees.php"
                        class="employee-reset-button"
                    >
                        Reset
                    </a>
                <?php endif; ?>

            </form>

        </section>

        <!-- EMPLOYEE SUMMARY -->

        <section class="employee-summary-row">

            <div class="employee-summary-card">
                <div class="employee-summary-icon">👥</div>

                <div>
                    <span>Total Employees</span>
                    <strong><?php echo count($employees); ?></strong>
                </div>
            </div>

            <div class="employee-summary-card">
                <div class="employee-summary-icon">🔎</div>

                <div>
                    <span>Showing</span>
                    <strong><?php echo count($filtered_employees); ?></strong>
                </div>
            </div>

        </section>

        <!-- EMPLOYEE TABLE -->

        <section class="employee-table-card">

            <div class="employee-table-header">

                <div>
                    <h2>Employee List</h2>

                    <p>
                        Select an employee to view their complete profile
                        and leave details.
                    </p>
                </div>

                <span class="employee-count">
                    <?php echo count($filtered_employees); ?> Employees
                </span>

            </div>

            <div class="employee-table-wrapper">

                <table class="employee-table">

                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Employee ID</th>
                            <th>Designation</th>
                            <th>Department</th>
                            <th>Date of Joining</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (!empty($filtered_employees)): ?>

                        <?php foreach ($filtered_employees as $employee): ?>

                            <tr>

                                <!-- EMPLOYEE NAME AND PHOTO -->

                                <td>
                                    <div class="employee-name-cell">

                                        <?php
                                        $picture = trim(
                                            $employee["profile_picture"] ?? ""
                                        );

                                        /*
                                         * Accept only stored paths inside
                                         * uploads/employee_photos/.
                                         */
                                        $valid_picture = (
                                            $picture !== "" &&
                                            strpos(
                                                $picture,
                                                "uploads/employee_photos/"
                                            ) === 0 &&
                                            strpos($picture, "..") === false &&
                                            strpos($picture, "\\") === false
                                        );

                                        $initial = strtoupper(
                                            substr(
                                                trim($employee["name"]) !== ""
                                                    ? trim($employee["name"])
                                                    : "?",
                                                0,
                                                1
                                            )
                                        );
                                        ?>

                                        <?php if ($valid_picture): ?>

                                            <img
                                                class="employee-avatar-photo"
                                                src="../<?php echo htmlspecialchars($picture, ENT_QUOTES, 'UTF-8'); ?>"
                                                alt="<?php echo htmlspecialchars($employee["name"], ENT_QUOTES, 'UTF-8'); ?> profile photo"
                                                loading="lazy"
                                                onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                            >

                                            <div
                                                class="employee-avatar-initial"
                                                style="display: none;"
                                                aria-label="No profile photo"
                                            >
                                                <?php echo htmlspecialchars($initial); ?>
                                            </div>

                                        <?php else: ?>

                                            <div
                                                class="employee-avatar-initial"
                                                aria-label="No profile photo"
                                            >
                                                <?php echo htmlspecialchars($initial); ?>
                                            </div>

                                        <?php endif; ?>

                                        <div>
                                            <strong>
                                                <?php
                                                echo htmlspecialchars($employee["name"]);
                                                ?>
                                            </strong>

                                            <span>
                                                Government Employee
                                            </span>
                                        </div>

                                    </div>
                                </td>

                                <!-- EMPLOYEE ID -->

                                <td>
                                    <span class="employee-id">
                                        <?php
                                        echo htmlspecialchars((string)$employee["id"]);
                                        ?>
                                    </span>
                                </td>

                                <!-- DESIGNATION -->

                                <td>
                                    <?php
                                    echo htmlspecialchars($employee["designation"]);
                                    ?>
                                </td>

                                <!-- DEPARTMENT -->

                                <td>
                                    <?php
                                    echo htmlspecialchars($employee["department"]);
                                    ?>
                                </td>

                                <!-- JOINING DATE -->

                                <td>
                                    <?php
                                    $date = DateTime::createFromFormat(
                                        "Y-m-d",
                                        $employee["joining_date"]
                                    );

                                    echo htmlspecialchars(
                                        $date
                                            ? $date->format("d-m-Y")
                                            : $employee["joining_date"]
                                    );
                                    ?>
                                </td>

                                <!-- ACTION -->

                                <td>
                                    <a
                                        href="employee_profile.php?id=<?php echo urlencode((string)$employee["id"]); ?>"
                                        class="view-employee-button"
                                    >
                                        View Profile
                                        <span>→</span>
                                    </a>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6" class="no-employees">

                                <div class="no-employees-icon">🔍</div>

                                <strong>No employees found</strong>

                                <p>
                                    Try changing your search or filters.
                                </p>

                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <!-- INFORMATION NOTE -->

        <section class="employee-information-card">

            <div class="information-icon">ℹ</div>

            <div>
                <strong>Employee Profile</strong>

                <p>
                    From an employee profile, the administrator can view
                    service details, leave balances, leave history, leave
                    calendar and earned leave surrender information.
                </p>
            </div>

        </section>

    </main>

</div>

<!-- SAVE & RESTORE PAGE SCROLL POSITION -->

<script>
window.addEventListener("beforeunload", function () {
    sessionStorage.setItem(
        "employees_scroll_position",
        window.scrollY
    );
});

window.addEventListener("load", function () {
    const savedPosition = sessionStorage.getItem(
        "employees_scroll_position"
    );

    if (savedPosition !== null) {
        window.scrollTo(0, parseInt(savedPosition, 10));
    }
});
</script>

</body>
</html>
