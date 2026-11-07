<?php

session_start();

require_once "db.php";


/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/

if (isset($_GET["action"]) && $_GET["action"] === "logout") {

    session_unset();
    session_destroy();

    header("Location: index.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| LOGIN REQUEST CHECK
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

    header("Location: index.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| GET LOGIN DETAILS
|--------------------------------------------------------------------------
*/

$username = trim($_POST["employee_id"] ?? "");
$password = $_POST["password"] ?? "";


/*
|--------------------------------------------------------------------------
| VALIDATE INPUT
|--------------------------------------------------------------------------
*/

if ($username === "" || $password === "") {

    echo "<script>
        alert('Please enter Employee ID and Password.');
        window.location.href = 'index.php';
    </script>";

    exit();
}


/*
|--------------------------------------------------------------------------
| FIND USER
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        employee_id,
        username,
        password_hash,
        role,
        status
    FROM users
    WHERE username = ?
    LIMIT 1
");

$stmt->bind_param("s", $username);

$stmt->execute();

$result = $stmt->get_result();

$user = $result->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| CHECK USER
|--------------------------------------------------------------------------
*/

if (!$user) {

    echo "<script>
        alert('User not found.');
        window.location.href = 'index.php';
    </script>";

    exit();
}


/*
|--------------------------------------------------------------------------
| CHECK STATUS
|--------------------------------------------------------------------------
*/

if ((int)$user["status"] !== 1) {

    echo "<script>
        alert('This account is inactive.');
        window.location.href = 'index.php';
    </script>";

    exit();
}


/*
|--------------------------------------------------------------------------
| CHECK PASSWORD
|--------------------------------------------------------------------------
*/

if (!password_verify($password, $user["password_hash"])) {

    echo "<script>
        alert('Invalid Employee ID or Password.');
        window.location.href = 'index.php';
    </script>";

    exit();
}


/*
|--------------------------------------------------------------------------
| LOGIN SUCCESS
|--------------------------------------------------------------------------
*/

session_regenerate_id(true);

$_SESSION["user_id"] = $user["id"];
$_SESSION["username"] = $user["username"];
$_SESSION["role"] = $user["role"];


/*
|--------------------------------------------------------------------------
| ADMIN LOGIN
|--------------------------------------------------------------------------
*/

if ($user["role"] === "admin") {

    $_SESSION["employee_id"] = null;

    header("Location: admin/admin.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| EMPLOYEE LOGIN
|--------------------------------------------------------------------------
*/

if ($user["role"] === "employee") {

    $_SESSION["employee_db_id"] = $user["employee_id"];

    /*
     * Get actual employee ID such as 123456
     */
    $employee_stmt = $conn->prepare("
        SELECT employee_id
        FROM employees
        WHERE id = ?
        LIMIT 1
    ");

    $employee_stmt->bind_param(
        "i",
        $user["employee_id"]
    );

    $employee_stmt->execute();

    $employee_result = $employee_stmt->get_result();

    $employee = $employee_result->fetch_assoc();

    $employee_stmt->close();


    if (!$employee) {

        session_unset();
        session_destroy();

        echo "<script>
            alert('Employee record not found.');
            window.location.href = 'index.php';
        </script>";

        exit();
    }


    $_SESSION["employee_id"] = $employee["employee_id"];

    header("Location: employee/dashboard.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| UNKNOWN ROLE
|--------------------------------------------------------------------------
*/

session_unset();
session_destroy();

echo "<script>
    alert('Invalid account role.');
    window.location.href = 'index.php';
</script>";

exit();

?>