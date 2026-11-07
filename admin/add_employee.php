
<?php

session_start();
require_once __DIR__ . "/../db.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../index.php");
    exit();
}

$errors = [];

$name = "";
$employee_id = "";
$designation = "";
$department = "";
$gender = "";
$joining_date = "";
$status = "active";
$initial_cl = "20";
$initial_el = "30";
$initial_hpl = "20";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $employee_id = trim($_POST["employee_id"] ?? "");
    $name = trim($_POST["name"] ?? "");
    $designation = trim($_POST["designation"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $gender = trim($_POST["gender"] ?? "");
    $joining_date = trim($_POST["joining_date"] ?? "");
    $status = $_POST["status"] ?? "active";

    $initial_cl = trim($_POST["initial_cl"] ?? "20");
    $initial_el = trim($_POST["initial_el"] ?? "30");
    $initial_hpl = trim($_POST["initial_hpl"] ?? "20");

    $initial_balances = [
        "CL" => $initial_cl,
        "EL" => $initial_el,
        "HPL" => $initial_hpl
    ];

    foreach ($initial_balances as $code => $amount) {
        if (
            !preg_match('/^\d{1,4}(\.\d{1,2})?$/', $amount) ||
            (float)$amount > 9999.99
        ) {
            $errors[] = "Enter a valid initial $code balance (0 to 9999.99).";
        }
    }

    if ($employee_id === "" || !preg_match('/^[A-Za-z0-9_-]{1,20}$/', $employee_id)) {
        $errors[] = "Enter a valid Employee ID (up to 20 letters, numbers, _ or -).";
    }

    if ($name === "" || mb_strlen($name) > 150) {
        $errors[] = "Enter the employee's name (maximum 150 characters).";
    }

    if ($designation === "" || mb_strlen($designation) > 100) {
        $errors[] = "Enter a valid designation.";
    }

    if ($department === "" || mb_strlen($department) > 100) {
        $errors[] = "Enter a valid department.";
    }

    if (!in_array($gender, ["Male", "Female"], true)) {
        $errors[] = "Select a valid gender.";
    }

    $date = DateTime::createFromFormat("!Y-m-d", $joining_date);
    $date_errors = DateTime::getLastErrors();

    if (
        !$date ||
        $date->format("Y-m-d") !== $joining_date ||
        ($date_errors !== false &&
            ($date_errors["warning_count"] > 0 || $date_errors["error_count"] > 0))
    ) {
        $errors[] = "Select a valid date of joining.";
    }

    if (!in_array($status, ["active", "inactive"], true)) {
        $errors[] = "Select a valid employee status.";
    }

    // Check duplicate employee ID.
    if (!$errors) {
        $stmt = $conn->prepare(
            "SELECT id FROM employees WHERE employee_id = :employee_id LIMIT 1"
        );

        $stmt->execute([
            ":employee_id" => $employee_id
        ]);

        $existing = $stmt->fetch();

        if ($existing) {
            $errors[] = "This Employee ID already exists.";
        }
    }

    // Check duplicate login username.
    if (!$errors) {
        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE username = :username LIMIT 1"
        );

        $stmt->execute([
            ":username" => $employee_id
        ]);

        $existing_user = $stmt->fetch();

        if ($existing_user) {
            $errors[] = "A login account with this Employee ID already exists.";
        }
    }

    // Validate profile photo.
    $uploaded_photo_tmp = null;
    $photo_extension = null;

    if (
        isset($_FILES["profile_picture"]) &&
        $_FILES["profile_picture"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {
        $photo = $_FILES["profile_picture"];

        if ($photo["error"] !== UPLOAD_ERR_OK) {
            $errors[] = "The photo upload failed. Please try again.";
        } elseif ($photo["size"] > 2 * 1024 * 1024) {
            $errors[] = "The profile photo must be 2 MB or smaller.";
        } elseif (!is_uploaded_file($photo["tmp_name"])) {
            $errors[] = "Invalid photo upload.";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($photo["tmp_name"]);

            $allowed_types = [
                "image/jpeg" => "jpg",
                "image/png"  => "png",
                "image/webp" => "webp"
            ];

            if (!isset($allowed_types[$mime])) {
                $errors[] = "Upload a JPG, PNG, or WebP image.";
            } elseif (@getimagesize($photo["tmp_name"]) === false) {
                $errors[] = "The uploaded file is not a valid image.";
            } else {
                $uploaded_photo_tmp = $photo["tmp_name"];
                $photo_extension = $allowed_types[$mime];
            }
        }
    }

    // Save employee and related records.
    if (!$errors) {

        $destination = null;

        try {

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                INSERT INTO employees
                    (
                        employee_id,
                        name,
                        designation,
                        department,
                        gender,
                        joining_date,
                        status
                    )
                VALUES
                    (
                        :employee_id,
                        :name,
                        :designation,
                        :department,
                        :gender,
                        :joining_date,
                        :status
                    )
            ");

            $stmt->execute([
                ":employee_id" => $employee_id,
                ":name" => $name,
                ":designation" => $designation,
                ":department" => $department,
                ":gender" => $gender,
                ":joining_date" => $joining_date,
                ":status" => $status
            ]);

            $employee_db_id = (int)$conn->lastInsertId();

            $saved_photo_path = null;

            if ($uploaded_photo_tmp !== null) {

                $upload_dir = __DIR__ . "/../uploads/employee_photos/";

                if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
                    throw new Exception("Could not create the photo upload directory.");
                }

                $photo_filename = bin2hex(random_bytes(16)) . "." . $photo_extension;
                $destination = $upload_dir . $photo_filename;

                if (!move_uploaded_file($uploaded_photo_tmp, $destination)) {
                    throw new Exception("Could not save the profile photo.");
                }

                $saved_photo_path = "uploads/employee_photos/" . $photo_filename;

                $stmt = $conn->prepare("
                    UPDATE employees
                    SET profile_picture = :profile_picture
                    WHERE id = :id
                ");

                $stmt->execute([
                    ":profile_picture" => $saved_photo_path,
                    ":id" => $employee_db_id
                ]);
            }

            // Create employee login account.
            $password_hash = password_hash($employee_id, PASSWORD_DEFAULT);
            $role = "employee";
            $user_status = ($status === "active") ? 1 : 0;

            $stmt = $conn->prepare("
                INSERT INTO users
                    (employee_id, username, password_hash, role, status)
                VALUES
                    (:employee_id, :username, :password_hash, :role, :status)
            ");

            $stmt->execute([
                ":employee_id" => $employee_db_id,
                ":username" => $employee_id,
                ":password_hash" => $password_hash,
                ":role" => $role,
                ":status" => $user_status
            ]);

            // Create initial leave balances.
            $balance_year = (int)date("Y");

            $balance_stmt = $conn->prepare("
                INSERT INTO leave_balances
                    (
                        employee_id,
                        leave_type_id,
                        year,
                        opening_balance,
                        credited,
                        used,
                        surrendered,
                        closing_balance
                    )
                SELECT
                    :employee_id,
                    id,
                    :year,
                    :opening_balance,
                    0,
                    0,
                    0,
                    :closing_balance
                FROM leave_types
                WHERE code = :code
                LIMIT 1
            ");

            $initial_balances = [
                "CL" => (float)$initial_cl,
                "EL" => (float)$initial_el,
                "HPL" => (float)$initial_hpl
            ];

            foreach ($initial_balances as $code => $amount) {

                $balance_stmt->execute([
                    ":employee_id" => $employee_db_id,
                    ":year" => $balance_year,
                    ":opening_balance" => $amount,
                    ":closing_balance" => $amount,
                    ":code" => $code
                ]);

                if ($balance_stmt->rowCount() !== 1) {
                    throw new Exception(
                        "Could not find the $code leave type or create its balance."
                    );
                }
            }

            $conn->commit();

            header("Location: employees.php?added=1");
            exit();

        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            if ($destination !== null && is_file($destination)) {
                @unlink($destination);
            }

            error_log("Add employee error: " . $e->getMessage());

            $errors[] = "The employee could not be saved. Check the database and upload folder permissions, then try again.";
        }
    }
}

$admin_name = "Admin";

?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Employee | EMMRC</title>

    <link rel="stylesheet" href="../css/style.css?v=2">

    <link rel="stylesheet" href="../css/responsive.css?v=1">
</head>

<body>

<div class="admin-page">

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
                <strong><?php echo htmlspecialchars($admin_name); ?></strong>
                <span>Administrator</span>
            </div>

            <a href="../login.php?action=logout" class="admin-logout">
                ↪ Logout
            </a>
        </div>

    </header>

    <main class="admin-container">

        <section class="admin-page-header">

            <div>
                <a href="employees.php" class="add-employee-back">
                    ← Back to Employee Management
                </a>

                <h1>Add Employee</h1>
            </div>

        </section>

        <section class="add-employee-card">

            <div class="add-employee-card-header">
                <h2>Employee Details</h2>
                <p>Fields marked with * are required.</p>
            </div>

            <div class="add-employee-notice">
                <strong>Initial login details</strong><br>
                The Employee ID will be used as the username and initial password.
                The password will be stored as a secure hash.
            </div>

            <?php if (!empty($errors)): ?>
                <div class="add-employee-error" role="alert">
                    <strong>Please correct the following:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form
                method="POST"
                action="add_employee.php"
                enctype="multipart/form-data"
            >

                <div class="add-employee-grid">

                    <div class="add-employee-field">
                        <label for="employee_id">Employee ID *</label>

                        <input
                            type="text"
                            id="employee_id"
                            name="employee_id"
                            maxlength="20"
                            value="<?php echo htmlspecialchars($employee_id); ?>"
                            required
                        >

                        <span class="add-employee-help">
                            Must be unique. This becomes the initial username and password.
                        </span>
                    </div>

                    <div class="add-employee-field">
                        <label for="name">Full Name *</label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            maxlength="150"
                            value="<?php echo htmlspecialchars($name); ?>"
                            required
                        >
                    </div>

                    <div class="add-employee-field">
                        <label for="designation">Designation *</label>

                        <input
                            type="text"
                            id="designation"
                            name="designation"
                            maxlength="100"
                            value="<?php echo htmlspecialchars($designation); ?>"
                            required
                        >
                    </div>

                    <div class="add-employee-field">
                        <label for="department">Department *</label>

                        <input
                            type="text"
                            id="department"
                            name="department"
                            maxlength="100"
                            value="<?php echo htmlspecialchars($department); ?>"
                            required
                        >

                        <span class="add-employee-help">
                            Example: Administration, Accounts, Establishment.
                        </span>
                    </div>

                    <div class="add-employee-field">
                        <label for="gender">Gender *</label>
                        <select id="gender" name="gender" required>
                            <option value="">Select gender</option>
                            <option value="Male" <?php echo $gender === "Male" ? "selected" : ""; ?>>Male</option>
                            <option value="Female" <?php echo $gender === "Female" ? "selected" : ""; ?>>Female</option>
                        </select>
                    </div>

                    <div class="add-employee-field">
                        <label for="joining_date">Date of Joining *</label>

                        <input
                            type="date"
                            id="joining_date"
                            name="joining_date"
                            value="<?php echo htmlspecialchars($joining_date); ?>"
                            required
                        >
                    </div>

                    <div class="add-employee-field">
                        <label for="status">Employee Status *</label>

                        <select id="status" name="status" required>
                            <option value="active"
                                <?php echo $status === "active" ? "selected" : ""; ?>>
                                Active
                            </option>

                            <option value="inactive"
                                <?php echo $status === "inactive" ? "selected" : ""; ?>>
                                Inactive
                            </option>
                        </select>
                    </div>

                    <div class="add-employee-field">
    <label for="initial_cl">Initial CL Balance</label>
    <input
        type="number"
        id="initial_cl"
        name="initial_cl"
        min="0"
        max="9999.99"
        step="0.01"
        value="<?php echo htmlspecialchars($initial_cl); ?>"
        required
    >
    <span class="add-employee-help">Default: 20 days</span>
</div>

<div class="add-employee-field">
    <label for="initial_el">Initial EL Balance</label>
    <input
        type="number"
        id="initial_el"
        name="initial_el"
        min="0"
        max="9999.99"
        step="0.01"
        value="<?php echo htmlspecialchars($initial_el); ?>"
        required
    >
    <span class="add-employee-help">Default: 30 days</span>
</div>

<div class="add-employee-field">
    <label for="initial_hpl">Initial HPL Balance</label>
    <input
        type="number"
        id="initial_hpl"
        name="initial_hpl"
        min="0"
        max="9999.99"
        step="0.01"
        value="<?php echo htmlspecialchars($initial_hpl); ?>"
        required
    >
    <span class="add-employee-help">Default: 20 days</span>
</div>

                    <div class="add-employee-field full-width">
                        <label for="profile_picture">Profile Picture</label>

                        <input
                            type="file"
                            id="profile_picture"
                            name="profile_picture"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >

                        <span class="add-employee-help">
                            Optional. JPG, PNG, or WebP only. Maximum size: 2 MB.
                        </span>
                    </div>

                </div>

                <div class="add-employee-buttons">

                    <a href="employees.php" class="add-employee-cancel">
                        Cancel
                    </a>

                    <button type="submit" class="add-employee-save">
                        Save Employee
                    </button>

                </div>

            </form>

        </section>

    </main>

</div>


<script>
document.addEventListener("DOMContentLoaded", function () {
    const photoInput = document.getElementById("profile_picture");
    const employeeForm = photoInput.closest("form");
    const maxSize = 2 * 1024 * 1024;

    async function validatePhoto(file) {
        if (!file) {
            return true;
        }

        const allowedTypes = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        if (!allowedTypes.includes(file.type)) {
            alert("Invalid image format. Please select a JPG, PNG, or WebP image.");
            return false;
        }

        if (file.size > maxSize) {
            alert("Image is too large. Please select an image under 2 MB.");
            return false;
        }

        if (file.size === 0) {
            alert("The selected image is empty. Please choose another image.");
            return false;
        }

        try {
            const imageURL = URL.createObjectURL(file);

            try {
                const image = new Image();

                await new Promise((resolve, reject) => {
                    image.onload = resolve;
                    image.onerror = reject;
                    image.src = imageURL;
                });

                if (image.naturalWidth === 0 || image.naturalHeight === 0) {
                    throw new Error("Invalid image dimensions");
                }
            } finally {
                URL.revokeObjectURL(imageURL);
            }

            return true;
        } catch (error) {
            alert("The selected file is not a valid image or is corrupted. Please choose another image.");
            return false;
        }
    }

    photoInput.addEventListener("change", async function () {
        const file = photoInput.files[0];

        if (!file) {
            return;
        }

        const isValid = await validatePhoto(file);

        if (!isValid) {
            photoInput.value = "";
        }
    });

    employeeForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        const file = photoInput.files[0];
        const isValid = await validatePhoto(file);

        if (isValid) {
            employeeForm.submit();
        } else {
            photoInput.value = "";
        }
    });
});
</script>

</body>
</html>
