
<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {

    $database_url = getenv("DATABASE_URL");

    if (!$database_url) {
        throw new Exception("DATABASE_URL is missing");
    }

    $db = parse_url($database_url);

    if (
        !$db ||
        empty($db["host"]) ||
        empty($db["user"]) ||
        !isset($db["pass"]) ||
        empty($db["path"])
    ) {
        throw new Exception("Invalid database URL");
    }

    $host = $db["host"];
    $username = urldecode($db["user"]);
    $password = urldecode($db["pass"]);
    $database = urldecode(ltrim($db["path"], "/"));
    $port = $db["port"] ?? 3306;

    $conn = new mysqli(
        $host,
        $username,
        $password,
        $database,
        $port
    );

    $conn->set_charset("utf8mb4");

} catch (Throwable $e) {

    error_log("Database connection error: " . $e->getMessage());

    http_response_code(500);

    exit("Database connection failed. Contact administrator.");

}
