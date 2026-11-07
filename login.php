
<?php

session_start();

require_once __DIR__ . "/db.php";

if (isset($_GET["action"]) && $_GET["action"] === "logout") {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit();
}

$username = trim($_POST["employee_id"] ?? "");
$password = $_POST["password"] ?? "";

if ($username === "" || $password === "") {
    echo "<script>
        alert('Please enter Employee ID and Password.');
        window.location.href = 'index.php';
    </script>";
    exit();
}

$stmt = $conn->prepare("
    SELECT id, employee_id, username, password_hash, role, status
    FROM users
    WHERE username = :username
    LIMIT 1
");

$stmt->execute([":username" => $username]);
$user = $stmt->fetch();

if (!$user) {
    echo "<script>
        alert('User not found.');
        window.location.href = 'index.php';
    </script>";
    exit();
}

if ((int)$user["status"] !== 1) {
    echo "<script>
        alert('This account is inactive.');
        window.location.href = 'index.php';
    </script>";
    exit();
}

if (!password_verify($password, $user["password_hash"])) {
    echo "<script>
        alert('Invalid Employee ID or Password.');
        window.location.href = 'index.php';
    </script>";
    exit();
}

session_regenerate_id(true);

$_SESSION["user_id"] = $user["id"];
$_SESSION["username"] = $user["username"];
$_SESSION["role"] = $user["role"];

if ($user["role"] === "admin") {
    $_SESSION["employee_id"] = null;
    header("Location: admin/admin.php");
    exit();
}

if ($user["role"] === "employee") {

    $_SESSION["employee_db_id"] = $user["employee_id"];

    $employee_stmt = $conn->prepare("
        SELECT employee_id
        FROM employees
        WHERE id = :id
        LIMIT 1
    ");

    $employee_stmt->execute([
        ":id" => $user["employee_id"]
    ]);

    $employee = $employee_stmt->fetch();

    $employee_stmt->closeCursor();

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

session_unset();
session_destroy();

echo "<script>
    alert('Invalid account role.');
    window.location.href = 'index.php';
</script>";

exit();

?>
