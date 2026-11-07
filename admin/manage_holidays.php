<?php

session_start();

require_once "../db.php";


/* =========================================================
   ADMIN ACCESS CHECK
========================================================= */

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {

    header("Location: ../index.php");
    exit();

}


/* =========================================================
   MESSAGE VARIABLES
========================================================= */

$message = "";
$message_type = "";


/* =========================================================
   PAGINATION
========================================================= */

$items_per_page = 10;

$current_page = isset($_GET["page"])
    ? max(1, (int)$_GET["page"])
    : 1;

$offset = ($current_page - 1) * $items_per_page;


/* =========================================================
   ADD / DELETE HOLIDAY
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";


    /* =====================================================
       ADD HOLIDAY
    ===================================================== */

    if ($action === "add") {

        $holiday_date = trim($_POST["holiday_date"] ?? "");
        $description = trim($_POST["holiday_name"] ?? "");


        /* ---------------------------------------------
           VALIDATION
        --------------------------------------------- */

        if ($holiday_date === "" || $description === "") {

            $message = "Please enter the holiday date and name.";
            $message_type = "error";

        } else {

            $date_object = DateTime::createFromFormat(
                "Y-m-d",
                $holiday_date
            );

            $valid_date =
                $date_object &&
                $date_object->format("Y-m-d") === $holiday_date;


            if (!$valid_date) {

                $message = "Invalid holiday date.";
                $message_type = "error";

            } else {

                $day = $date_object->format("l");


                /* -----------------------------------------
                   CHECK DUPLICATE DATE
                ----------------------------------------- */

                $check = $conn->prepare("
                    SELECT id
                    FROM holidays
                    WHERE holiday_date = ?
                    LIMIT 1
                ");

                $check->bind_param(
                    "s",
                    $holiday_date
                );

                $check->execute();

                $exists = $check
                    ->get_result()
                    ->fetch_assoc();

                $check->close();


                if ($exists) {

                    $message =
                        "A holiday already exists for this date.";

                    $message_type = "error";

                } else {


                    /* -------------------------------------
                       MANUALLY ADDED HOLIDAY
                    ------------------------------------- */

                    $source_type = "M";


                    $stmt = $conn->prepare("
                        INSERT INTO holidays
                        (
                            holiday_date,
                            day,
                            description,
                            source_type
                        )
                        VALUES (?, ?, ?, ?)
                    ");


                    if (!$stmt) {

                        $message =
                            "Database error: unable to prepare holiday.";

                        $message_type = "error";

                    } else {

                        $stmt->bind_param(
                            "ssss",
                            $holiday_date,
                            $day,
                            $description,
                            $source_type
                        );


                        if ($stmt->execute()) {

                            $message =
                                "Holiday added successfully.";

                            $message_type = "success";

                            /*
                             * Go back to page 1 so the newly
                             * added holiday appears at the top.
                             */
                            $current_page = 1;
                            $offset = 0;

                        } else {

                            $message =
                                "Unable to add the holiday.";

                            $message_type = "error";

                        }

                        $stmt->close();

                    }

                }

            }

        }

    }


    /* =====================================================
       DELETE HOLIDAY
    ===================================================== */

    elseif ($action === "delete") {

        $holiday_id =
            (int)($_POST["holiday_id"] ?? 0);


        if ($holiday_id > 0) {

            $stmt = $conn->prepare("
                DELETE FROM holidays
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "i",
                $holiday_id
            );


            if (
                $stmt->execute() &&
                $stmt->affected_rows > 0
            ) {

                $message =
                    "Holiday deleted successfully.";

                $message_type = "success";

            } else {

                $message =
                    "Holiday could not be deleted.";

                $message_type = "error";

            }

            $stmt->close();

        }

    }

}


/* =========================================================
   TOTAL HOLIDAY COUNT
========================================================= */

$total_holidays = 0;


$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM holidays
");


$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();

$total_holidays =
    (int)($row["total"] ?? 0);

$stmt->close();


/* =========================================================
   TOTAL PAGES
========================================================= */

$total_pages = max(
    1,
    (int)ceil(
        $total_holidays / $items_per_page
    )
);


/*
 * If current page becomes larger than
 * the available pages, move to the last page.
 */

if ($current_page > $total_pages) {

    $current_page = $total_pages;

    $offset =
        ($current_page - 1) *
        $items_per_page;

}


/* =========================================================
   LOAD HOLIDAYS
   LATEST / LAST COME FIRST
========================================================= */

$holidays = [];


$stmt = $conn->prepare("
    SELECT
        id,
        holiday_date,
        day,
        description,
        source_type
    FROM holidays
    ORDER BY
        holiday_date DESC,
        id DESC
    LIMIT ? OFFSET ?
");


$stmt->bind_param(
    "ii",
    $items_per_page,
    $offset
);


$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {

    $holidays[] = $row;

}


$stmt->close();


/* =========================================================
   SEE MORE / NEXT PAGE
========================================================= */

$has_next_page =
    $current_page < $total_pages;

$has_previous_page =
    $current_page > 1;

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
        Manage Holidays | EMMRC
    </title>


    <link
        rel="stylesheet"
        href="../css/style.css?v=2"
    >


    <style>

        /* =====================================================
           PAGE LAYOUT
        ===================================================== */

        .holiday-page {

            min-height: 100vh;

            height: auto;

            background: #f6f8fc;

            overflow: visible;

        }


        body:has(.holiday-page) {

            overflow-x: hidden;

            overflow-y: auto;

        }


        .holiday-container {

            width: 92%;

            max-width: 1200px;

            margin: 30px auto 50px;

        }


        /* =====================================================
           PAGE TITLE
        ===================================================== */

        .holiday-title {

            margin-bottom: 25px;

        }


        .holiday-title h1 {

            margin: 0;

            color: #263b60;

            font-size: 24px;

        }


        .holiday-title p {

            margin: 7px 0 0;

            color: #7b8799;

            font-size: 13px;

        }


        /* =====================================================
           CARDS
        ===================================================== */

        .holiday-card {

            background: #ffffff;

            border-radius: 12px;

            padding: 25px;

            margin-bottom: 25px;

            box-shadow:
                0 4px 15px
                rgba(30, 50, 90, 0.07);

        }


        .holiday-card h2 {

            margin: 0 0 20px;

            color: #263b60;

            font-size: 17px;

        }


        /* =====================================================
           ADD HOLIDAY FORM
        ===================================================== */

        .holiday-form {

            display: grid;

            grid-template-columns:
                1fr 1fr 1fr auto;

            gap: 15px;

            align-items: end;

        }


        .form-group {

            display: flex;

            flex-direction: column;

            gap: 7px;

        }


        .form-group label {

            color: #59729b;

            font-size: 12px;

            font-weight: 600;

        }


        .form-group input {

            width: 100%;

            height: 40px;

            padding: 0 12px;

            border:
                1px solid #dce2ec;

            border-radius: 7px;

            box-sizing: border-box;

            outline: none;

            font-family: inherit;

            font-size: 13px;

        }


        .form-group input:focus {

            border-color: #526ee8;

        }


        /* =====================================================
           ADD BUTTON
        ===================================================== */

        .add-holiday-button {

            height: 40px;

            padding: 0 20px;

            border: none;

            border-radius: 7px;

            background: #526ee8;

            color: #ffffff;

            font-family: inherit;

            font-size: 13px;

            font-weight: 600;

            cursor: pointer;

        }


        .add-holiday-button:hover {

            background: #435ed4;

        }


        .add-holiday-button:disabled {

            opacity: 0.7;

            cursor: not-allowed;

        }


        /* =====================================================
           HOLIDAY TABLE
        ===================================================== */

        .holiday-table-wrapper {

            overflow-x: auto;

        }


        .holiday-table {

            width: 100%;

            border-collapse: collapse;

        }


        .holiday-table th {

            padding: 13px 12px;

            background: #f6f8fc;

            color: #59729b;

            text-align: left;

            font-size: 11px;

            font-weight: 700;

        }


        .holiday-table td {

            padding: 14px 12px;

            border-bottom:
                1px solid #edf0f5;

            color: #425574;

            font-size: 13px;

        }


        .holiday-name {

            color: #263b60;

            font-weight: 600;

        }


        /* =====================================================
           DELETE BUTTON
        ===================================================== */

        .delete-button {

            padding: 7px 12px;

            border: none;

            border-radius: 6px;

            background: #fff0f1;

            color: #d95361;

            font-family: inherit;

            font-size: 11px;

            font-weight: 600;

            cursor: pointer;

        }


        .delete-button:hover {

            background: #ffe1e4;

        }


        /* =====================================================
           BACK BUTTON
        ===================================================== */

        .holiday-back {

            display: inline-block;

            margin-bottom: 20px;

            color: #526ee8;

            text-decoration: none;

            font-size: 13px;

            font-weight: 600;

        }


        .holiday-back:hover {

            text-decoration: none;

        }


        /* =====================================================
           MESSAGE
        ===================================================== */

        .holiday-message {

            margin-top: 15px;

            padding: 10px 14px;

            border-radius: 8px;

            font-size: 13px;

        }


        .holiday-message.success {

            background: #eaf8f1;

            color: #26855b;

        }


        .holiday-message.error {

            background: #fff0f1;

            color: #d95361;

        }


        /* =====================================================
           PAGINATION
        ===================================================== */

        .holiday-pagination {

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 10px;

            margin-top: 22px;

        }


        .holiday-page-button {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            min-width: 105px;

            height: 38px;

            padding: 0 16px;

            border-radius: 7px;

            border: 1px solid #dce2ec;

            background: #ffffff;

            color: #526ee8;

            text-decoration: none;

            font-size: 12px;

            font-weight: 600;

            transition: 0.2s ease;

        }


        .holiday-page-button:hover {

            background: #f3f5ff;

            border-color: #526ee8;

        }


        .holiday-page-button.disabled {

            opacity: 0.45;

            pointer-events: none;

        }


        .holiday-page-number {

            color: #7b8799;

            font-size: 12px;

            padding: 0 5px;

        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 850px) {

            .holiday-form {

                grid-template-columns:
                    1fr 1fr;

            }

        }


        @media (max-width: 550px) {

            .holiday-form {

                grid-template-columns:
                    1fr;

            }


            .holiday-container {

                width: 94%;

            }


            .holiday-pagination {

                flex-wrap: wrap;

            }

        }

    </style>


    <link rel="stylesheet" href="../css/responsive.css?v=1">
</head>


<body>


<div class="holiday-page">


    <!-- =====================================================
         ADMIN HEADER
    ===================================================== -->

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

                <strong>
                    Leave Management
                </strong>

                <span>
                    Admin Portal
                </span>

            </div>

        </div>


        <div class="admin-user">

            <div class="admin-avatar">
                A
            </div>


            <div class="admin-user-details">

                <strong>
                    Admin
                </strong>

                <span>
                    Administrator
                </span>

            </div>


            <a
                href="../login.php?action=logout"
                class="admin-logout"
            >
                ↪ Logout
            </a>

        </div>

    </header>



    <!-- =====================================================
         MAIN CONTENT
    ===================================================== -->

    <div class="holiday-container">


        <!-- BACK -->

        <a
            href="admin.php"
            class="holiday-back"
        >
            ← Back to Dashboard
        </a>



        <!-- PAGE TITLE -->

        <div class="holiday-title">

            <h1>
                Manage Holidays
            </h1>

            <p>
                Add and manage common holidays for the employee leave calendar.
            </p>


            <?php if ($message !== ""): ?>

                <div
                    class="holiday-message
                    <?php echo
                        $message_type === "success"
                            ? "success"
                            : "error";
                    ?>"
                >

                    <?php
                    echo htmlspecialchars($message);
                    ?>

                </div>

            <?php endif; ?>

        </div>



        <!-- =================================================
             ADD HOLIDAY
        ================================================= -->

        <div class="holiday-card">

            <h2>
                Add Holiday
            </h2>


            <form
                class="holiday-form"
                id="holidayForm"
                method="POST"
                action=""
            >

                <input
                    type="hidden"
                    name="action"
                    value="add"
                >


                <!-- DATE -->

                <div class="form-group">

                    <label for="holiday_date">
                        Holiday Date
                    </label>

                    <input
                        type="date"
                        id="holiday_date"
                        name="holiday_date"
                        required
                    >

                </div>


                <!-- NAME -->

                <div class="form-group">

                    <label for="holiday_name">
                        Holiday Name
                    </label>

                    <input
                        type="text"
                        id="holiday_name"
                        name="holiday_name"
                        placeholder="Enter holiday name"
                        required
                    >

                </div>


                <!-- TYPE -->

                <div class="form-group">

                    <label for="holiday_type">
                        Holiday Type
                    </label>

                    <input
                        type="text"
                        id="holiday_type"
                        name="holiday_type"
                        value="Common Holiday"
                        readonly
                    >

                </div>


                <!-- BUTTON -->

                <button
                    type="submit"
                    class="add-holiday-button"
                >
                    + Add Holiday
                </button>


            </form>

        </div>



        <!-- =================================================
             HOLIDAY LIST
        ================================================= -->

        <div class="holiday-card">

            <h2>
                Holiday List
            </h2>


            <div class="holiday-table-wrapper">

                <table class="holiday-table">

                    <thead>

                        <tr>

                            <th>
                                Date
                            </th>

                            <th>
                                Holiday
                            </th>

                            <th>
                                Type
                            </th>

                            <th>
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (empty($holidays)): ?>

                        <tr>

                            <td
                                colspan="4"
                                style="
                                    text-align:center;
                                    color:#8993a5;
                                    padding:25px;
                                "
                            >
                                No holidays found.
                            </td>

                        </tr>


                    <?php else: ?>


                        <?php foreach ($holidays as $holiday): ?>


                            <tr>


                                <!-- DATE -->

                                <td>

                                    <?php

                                    echo htmlspecialchars(
                                        date(
                                            "d-m-Y",
                                            strtotime(
                                                $holiday["holiday_date"]
                                            )
                                        )
                                    );

                                    ?>

                                </td>


                                <!-- HOLIDAY -->

                                <td class="holiday-name">

                                    <?php

                                    echo htmlspecialchars(
                                        $holiday["description"]
                                    );

                                    ?>

                                </td>


                                <!-- TYPE -->

                                <td>

                                    <?php

                                    $type =
                                        $holiday["source_type"]
                                        ?? "";

                                    echo htmlspecialchars(

                                        $type === "M" ||
                                        $type === ""

                                            ? "Common Holiday"

                                            : $type

                                    );

                                    ?>

                                </td>


                                <!-- DELETE -->

                                <td>

                                    <form
                                        method="POST"
                                        action=""
                                        style="margin:0;"
                                        onsubmit="
                                            return confirm(
                                                'Are you sure you want to delete this holiday?'
                                            );
                                        "
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >


                                        <input
                                            type="hidden"
                                            name="holiday_id"
                                            value="<?php
                                                echo (int)
                                                    $holiday["id"];
                                            ?>"
                                        >


                                        <button
                                            type="submit"
                                            class="delete-button"
                                        >
                                            Delete
                                        </button>

                                    </form>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>



            <!-- =================================================
                 PAGINATION
            ================================================= -->

            <?php if ($total_holidays > $items_per_page): ?>

                <div class="holiday-pagination">


                    <!-- PREVIOUS -->

                    <?php if ($has_previous_page): ?>

                        <a
                            href="?page=<?php
                                echo $current_page - 1;
                            ?>"
                            class="holiday-page-button"
                        >
                            ← Previous
                        </a>

                    <?php else: ?>

                        <span
                            class="
                                holiday-page-button
                                disabled
                            "
                        >
                            ← Previous
                        </span>

                    <?php endif; ?>



                    <!-- PAGE NUMBER -->

                    <span class="holiday-page-number">

                        Page
                        <?php echo $current_page; ?>
                        of
                        <?php echo $total_pages; ?>

                    </span>



                    <!-- SEE MORE -->

                    <?php if ($has_next_page): ?>

                        <a
                            href="?page=<?php
                                echo $current_page + 1;
                            ?>"
                            class="holiday-page-button"
                        >
                            See More →
                        </a>

                    <?php else: ?>

                        <span
                            class="
                                holiday-page-button
                                disabled
                            "
                        >
                            No More
                        </span>

                    <?php endif; ?>


                </div>

            <?php endif; ?>


        </div>


    </div>

</div>



<script>

/* =========================================================
   PREVENT DUPLICATE HOLIDAY SUBMISSION
========================================================= */

const holidayForm =
    document.getElementById("holidayForm");


if (holidayForm) {

    holidayForm.addEventListener(
        "submit",
        function () {

            const button =
                this.querySelector(
                    ".add-holiday-button"
                );


            if (button) {

                button.disabled = true;

                button.textContent =
                    "Adding...";

            }

        }
    );

}

</script>


</body>

</html>
