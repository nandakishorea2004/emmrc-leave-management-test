
<?php

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

    $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";

    $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

} catch (Throwable $e) {

    error_log("Database connection error: " . $e->getMessage());

    http_response_code(500);

    exit("Database connection failed. Contact administrator.");

}
