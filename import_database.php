<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

$token = getenv('IMPORT_TOKEN') ?: '';

if ($token === '') {
    http_response_code(500);
    exit('Importer is not configured.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = $_POST['token'] ?? '';

    if (!is_string($provided) || !hash_equals($token, $provided)) {
        http_response_code(403);
        exit('Access denied.');
    }

    if (
        !isset($_FILES['sql_file']) ||
        $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK ||
        $_FILES['sql_file']['size'] > 2 * 1024 * 1024
    ) {
        exit('Upload failed or file exceeds 2 MB.');
    }

    $sql = file_get_contents($_FILES['sql_file']['tmp_name']);

    if ($sql === false || trim($sql) === '') {
        exit('The uploaded file is empty.');
    }

    // Remove a possible UTF-8 byte-order mark.
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);

    // Split SQL safely, respecting quoted strings and comments.
    function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($c === "\n") {
                    $lineComment = false;
                    $buffer .= "\n";
                }
                continue;
            }

            if ($blockComment) {
                if ($c === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $c;

                if ($c === '\\' && $quote !== '`' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }

                if ($c === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buffer .= $c;
                continue;
            }

            if ($c === '#' || ($c === '-' && $next === '-' &&
                ($i + 2 >= $length || ctype_space($sql[$i + 2])))) {
                $lineComment = true;
                if ($c === '-') {
                    $i++;
                }
                continue;
            }

            if ($c === '/' && $next === '*') {
                // Preserve MariaDB executable comments.
                $end = strpos($sql, '*/', $i + 2);
                if ($end !== false && substr($sql, $i + 2, 1) === '!') {
                    $buffer .= substr($sql, $i, $end + 2 - $i);
                    $i = $end + 1;
                } else {
                    $blockComment = true;
                    $i++;
                }
                continue;
            }

            if ($c === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }

            $buffer .= $c;
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        if ($quote !== null || $blockComment) {
            throw new RuntimeException('The SQL file appears incomplete.');
        }

        return $statements;
    }

    try {
        $statements = splitSqlStatements($sql);

        $dbName = $conn->query('SELECT DATABASE()')->fetchColumn();

$check = $conn->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?'
);
$check->execute([$dbName]);

if ((int) $check->fetchColumn() > 0) {
    throw new RuntimeException(
        'Target database is not empty. Import cancelled.'
    );
}

        $conn->exec('SET FOREIGN_KEY_CHECKS=0');

        $count = 0;

        foreach ($statements as $statement) {
            $clean = ltrim($statement);

            if ($clean === '') {
                continue;
            }

            // Never allow database switching or database deletion.
            if (preg_match('/^(CREATE|DROP)\s+DATABASE\b|^USE\b/i', $clean)) {
                throw new RuntimeException('Database switching/deletion statements are not allowed.');
            }

            // Ignore transaction wrappers; MariaDB DDL commits implicitly.
            if (preg_match('/^(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\b/i', $clean)) {
                continue;
            }

            $conn->exec($statement);
            $count++;
        }

        $conn->exec('SET FOREIGN_KEY_CHECKS=1');

        echo 'IMPORT COMPLETED. Statements executed: ' . $count;
        echo '<br>Please verify the tables and records before removing this importer.';
    } catch (Throwable $e) {
        try {
            $conn->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $ignored) {
        }

        error_log('Database import error: ' . $e->getMessage());
        http_response_code(500);
        exit('Import failed. Check the Skyhost application logs. Do not retry until the error is reviewed.');
    }

    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Database Import</title>
</head>
<body>
<h2>Database Import</h2>
<form method="POST" enctype="multipart/form-data">
    <label>Import token:</label><br>
    <input type="password" name="token" required autocomplete="off"><br><br>

    <label>SQL backup file:</label><br>
    <input type="file" name="sql_file" accept=".sql" required><br><br>

    <button type="submit">Import Database</button>
</form>
</body>
</html>
